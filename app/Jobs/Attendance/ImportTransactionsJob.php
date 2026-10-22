<?php

namespace App\Jobs\Attendance;

use App\Models\Attendance\ImportBatch;
use App\Models\Company;
use App\Services\Attendance\DataIssues;
use App\Services\Attendance\DeviceRegistry;
use App\Services\Attendance\PersonClassifier;
use App\Services\Attendance\TransactionFile;
use App\Services\Attendance\TransactionImport;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportTransactionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(TransactionFile $file, TransactionImport $import, DeviceRegistry $devices, PersonClassifier $people, DataIssues $issues): void
    {
        $companyId = DB::table('attendance_import_batches')->where('id', $this->batchId)->value('company_id');
        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $file, $import, $devices, $people, $issues): void {
            $batch = ImportBatch::query()->findOrFail($this->batchId);
            if ($this->latestState($batch) === 'completed') {
                $this->dispatchProcessing($batch);

                return;
            }
            try {
                $this->event($batch, 'processing');
                $path = Storage::disk('local')->path($batch->file_path);
                if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_sha256) {
                    throw new RuntimeException('Stored attendance file changed or is missing.');
                }
                $sheet = $file->inspect($path, $batch->file_name);
                $file->validateMapping($batch->mapping, $sheet['headers']);
                $chunk = [];
                $classified = [];
                foreach ($file->rows($path, $batch->mapping, $batch->file_name) as $row) {
                    $chunk[] = $row;
                    if (count($chunk) === 1000) {
                        $this->storeChunk($company, $batch, $chunk, $import, $devices, $people, $issues, $classified);
                        $chunk = [];
                    }
                }
                if ($chunk !== []) {
                    $this->storeChunk($company, $batch, $chunk, $import, $devices, $people, $issues, $classified);
                }
                $this->event($batch, 'completed', $this->finalCounts($batch));
                $this->dispatchProcessing($batch);
            } catch (\Throwable $exception) {
                $code = $exception instanceof ValidationException ? 'invalid_file'
                    : ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'Stored attendance file')
                        ? 'stored_file_unavailable' : 'processing_failed');
                $this->event($batch, 'failed', errorCode: $code);
                throw $exception;
            }
        });
    }

    private function storeChunk(Company $company, ImportBatch $batch, array $chunk, TransactionImport $import, DeviceRegistry $devices, PersonClassifier $people, DataIssues $issues, array &$classified): void
    {
        DB::transaction(function () use ($company, $batch, $chunk, $import, $devices, $people, $issues, &$classified): void {
            $bySerial = [];
            $byCode = [];
            $transactions = [];
            foreach ($chunk as $row) {
                $raw = $row['values'];
                if ($raw['serial_number'] !== '' && mb_strlen($raw['serial_number']) <= 128) {
                    $bySerial[$raw['serial_number']] = [$raw['device_name'], $raw['punch_state']];
                }
                if ($row['errors'] !== []) {
                    continue;
                }
                $byCode[$raw['person_code']] = $raw['department_label'];
                $key = $import->key($raw['person_code'], $row['punched_at'], $raw['punch_state'], $raw['serial_number']);
                $transactions[$key] ??= [
                    'company_id' => $company->id, 'person_code' => $raw['person_code'],
                    'person_name' => $raw['person_name'], 'department_label' => $raw['department_label'],
                    'punch_date' => $raw['punch_date'], 'punch_time' => $raw['punch_time'],
                    'punched_at' => $row['punched_at'], 'punch_state' => $raw['punch_state'],
                    'serial_number' => $raw['serial_number'], 'device_name' => $raw['device_name'],
                    'data_source' => $raw['data_source'], 'first_batch_id' => $batch->id, 'created_at' => now(),
                ];
            }
            $deviceIds = [];
            foreach ($bySerial as $serial => [$name, $state]) {
                $deviceIds[$serial] = $devices->register($company, $serial, $name, $state, $batch->id)->id;
            }
            foreach ($transactions as &$transaction) {
                $transaction['device_id'] = $deviceIds[$transaction['serial_number']];
            }
            unset($transaction);
            if ($transactions !== []) {
                DB::table('attendance_transactions')->insertOrIgnore(array_values($transactions));
            }
            $codes = array_values(array_unique(array_column($transactions, 'person_code')));
            $times = array_column($transactions, 'punched_at');
            $found = $transactions === [] ? collect() : DB::table('attendance_transactions')
                ->where('company_id', $company->id)->whereIn('person_code', $codes)
                ->whereBetween('punched_at', [min($times), max($times)])
                ->get(['id', 'person_code', 'punched_at', 'punch_state', 'serial_number'])
                ->keyBy(fn ($transaction): string => $import->key($transaction->person_code, $transaction->punched_at, $transaction->punch_state, $transaction->serial_number))
                ->filter(fn ($transaction, string $key): bool => isset($transactions[$key]));
            $firstSourceRows = $found->isEmpty() ? collect() : DB::table('attendance_transaction_sources')
                ->where('company_id', $company->id)->where('batch_id', $batch->id)
                ->whereIn('transaction_id', $found->pluck('id')->all())
                ->selectRaw('transaction_id, MIN(`row_number`) AS first_row')
                ->groupBy('transaction_id')->pluck('first_row', 'transaction_id');
            $firstInChunk = [];
            $sources = [];
            foreach ($chunk as $row) {
                $raw = $row['raw'];
                $values = $row['values'];
                $transaction = $row['errors'] === [] ? $found->get($import->key($values['person_code'], $row['punched_at'], $values['punch_state'], $values['serial_number'])) : null;
                if ($row['errors'] === [] && $transaction === null) {
                    throw new RuntimeException('Imported transaction could not be linked to its source.');
                }
                $firstRow = $transaction === null ? null : min(
                    (int) ($firstSourceRows->get($transaction->id) ?? $row['number']),
                    (int) ($firstInChunk[$transaction->id] ?? $row['number']),
                );
                if ($transaction !== null) {
                    $firstInChunk[$transaction->id] = $firstRow;
                }
                $sources[] = [
                    'company_id' => $company->id, 'transaction_id' => $transaction?->id,
                    'batch_id' => $batch->id, 'row_number' => $row['number'],
                    'duplicate_in_file' => $firstRow !== null && $firstRow < $row['number'],
                    'errors' => json_encode($row['errors'], JSON_THROW_ON_ERROR),
                    'person_code' => $raw['person_code'], 'person_name' => $raw['person_name'],
                    'department_label' => $raw['department_label'], 'punch_date' => $raw['punch_date'],
                    'punch_time' => $raw['punch_time'], 'punch_state' => $raw['punch_state'],
                    'serial_number' => $raw['serial_number'], 'device_name' => $raw['device_name'],
                    'data_source' => $raw['data_source'], 'created_at' => now(),
                ];
                if ($row['errors'] !== []) {
                    $issues->open($company, 'import_row_error', 'batch_row', $batch->id.':'.$row['number'], $batch->id, ['row' => $row['number'], 'codes' => $row['errors']]);
                }
            }
            DB::table('attendance_transaction_sources')->insertOrIgnore($sources);
            foreach ($byCode as $code => $department) {
                if (($classified[$code] ?? null) !== $department) {
                    $people->classify($company, (string) $code, $department, $batch->id);
                    $classified[$code] = $department;
                    if (count($classified) > 10000) {
                        unset($classified[array_key_first($classified)]);
                    }
                }
            }
            $this->event($batch, 'processing', ['through_row' => $chunk[array_key_last($chunk)]['number']]);
        });
    }

    private function finalCounts(ImportBatch $batch): array
    {
        $sources = DB::table('attendance_transaction_sources')->where('attendance_transaction_sources.company_id', $batch->company_id)
            ->where('attendance_transaction_sources.batch_id', $batch->id);

        return [
            'rows' => (clone $sources)->count(),
            'errors' => (clone $sources)->whereNull('transaction_id')->count(),
            'duplicate_in_file' => (clone $sources)->where('duplicate_in_file', true)->count(),
            'new' => DB::table('attendance_transactions')->where('company_id', $batch->company_id)->where('first_batch_id', $batch->id)->count(),
            'overlap' => (clone $sources)->join('attendance_transactions as t', function ($join): void {
                $join->on('t.id', '=', 'attendance_transaction_sources.transaction_id')
                    ->on('t.company_id', '=', 'attendance_transaction_sources.company_id');
            })
                ->where('t.first_batch_id', '!=', $batch->id)
                ->distinct()->count('attendance_transaction_sources.transaction_id'),
        ];
    }

    private function dispatchProcessing(ImportBatch $batch): void
    {
        $sources = DB::table('attendance_transaction_sources as s')
            ->join('attendance_transactions as t', function ($join): void {
                $join->on('t.id', '=', 's.transaction_id')->on('t.company_id', '=', 's.company_id');
            })
            ->where('s.company_id', $batch->company_id)->where('s.batch_id', $batch->id);
        $codes = (clone $sources)->distinct()->pluck('t.person_code')->all();
        if ($codes === []) {
            return;
        }
        $range = (clone $sources)->selectRaw('MIN(t.punched_at) AS first_at, MAX(t.punched_at) AS last_at')->first();
        $from = CarbonImmutable::parse($range->first_at)->subDay()->startOfDay()->toDateTimeString();
        $to = CarbonImmutable::parse($range->last_at)->addDay()->endOfDay()->toDateTimeString();
        ProcessAttendanceJob::dispatch($batch->company_id, $codes, $from, $to, 'import', $batch->id)->afterCommit();
    }

    private function latestState(ImportBatch $batch): ?string
    {
        return DB::table('attendance_import_batch_events')->where('company_id', $batch->company_id)
            ->where('batch_id', $batch->id)->orderByDesc('sequence')->value('state');
    }

    private function event(ImportBatch $batch, string $state, ?array $counts = null, ?string $errorCode = null): void
    {
        DB::transaction(function () use ($batch, $state, $counts, $errorCode): void {
            DB::table('attendance_import_batches')->where('company_id', $batch->company_id)->where('id', $batch->id)->lockForUpdate()->first();
            $sequence = (int) DB::table('attendance_import_batch_events')->where('company_id', $batch->company_id)
                ->where('batch_id', $batch->id)->max('sequence') + 1;
            DB::table('attendance_import_batch_events')->insert([
                'company_id' => $batch->company_id, 'batch_id' => $batch->id, 'sequence' => $sequence,
                'state' => $state, 'counts' => $counts === null ? null : json_encode($counts, JSON_THROW_ON_ERROR),
                'error_code' => $errorCode, 'created_at' => now(),
            ]);
        });
    }
}
