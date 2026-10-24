<?php

namespace App\Jobs\Leave;

use App\Models\Company;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceBatchEvent;
use App\Services\Attendance\DataIssues;
use App\Services\Attendance\PreviewKeys;
use App\Services\Leave\LeaveBalanceFile;
use App\Support\Tenancy\TenantContext;
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

class ImportLeaveBalanceJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(LeaveBalanceFile $file, PreviewKeys $keys, DataIssues $issues): void
    {
        $companyId = DB::table('leave_balance_batches')->where('id', $this->batchId)->value('company_id');
        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $file, $keys, $issues): void {
            $batch = BalanceBatch::query()->findOrFail($this->batchId);
            if ($this->latestState($batch) === 'completed') {
                return;
            }
            try {
                $this->event($batch, 'running');
                $path = Storage::disk('local')->path($batch->file_path);
                if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_sha256) {
                    throw new RuntimeException('Stored balance file changed or is missing.');
                }
                $sheet = $file->inspect($path, $batch->file_name);
                $file->validateMapping($batch->mapping, $sheet['headers']);
                $codeLength = DB::table('employees')->where('company_id', $company->id)
                    ->selectRaw('CHAR_LENGTH(employee_code) AS code_length, COUNT(*) AS total')
                    ->groupBy('code_length')->orderByDesc('total')->value('code_length');
                $keys->open();
                try {
                    $chunk = [];
                    foreach ($file->rows($path, $batch->mapping, $batch->file_name, $codeLength === null ? null : (int) $codeLength) as $row) {
                        $chunk[] = $row;
                        if (count($chunk) === 1000) {
                            $this->storeChunk($company, $batch, $chunk, $keys);
                            $chunk = [];
                        }
                    }
                    if ($chunk !== []) {
                        $this->storeChunk($company, $batch, $chunk, $keys);
                    }
                } finally {
                    $keys->close();
                }
                $unknown = DB::table('leave_balance_rows')->where('company_id', $company->id)
                    ->where('batch_id', $batch->id)->where('outcome', 'unknown');
                $unknownCount = (clone $unknown)->count();
                if ($unknownCount > 0) {
                    $issues->open($company, 'leave_balance_unknown', 'leave_balance_batch', 'batch:'.$batch->id,
                        details: ['count' => $unknownCount, 'codes' => (clone $unknown)->orderBy('row_number')->limit(20)->pluck('employee_code')->all()]);
                }
                $counts = ['rows' => DB::table('leave_balance_rows')->where('company_id', $company->id)->where('batch_id', $batch->id)->count()];
                foreach (['imported', 'unknown', 'duplicate', 'invalid'] as $outcome) {
                    $counts[$outcome] = DB::table('leave_balance_rows')->where('company_id', $company->id)
                        ->where('batch_id', $batch->id)->where('outcome', $outcome)->count();
                }
                $counts['negative'] = DB::table('leave_balances')->where('company_id', $company->id)
                    ->where('batch_id', $batch->id)->where('remaining_days', '<', 0)->count();
                $counts['missing'] = DB::table('employees')->where('company_id', $company->id)
                    ->whereNotExists(function ($query) use ($batch): void {
                        $query->selectRaw('1')->from('leave_balance_rows as seen')
                            ->whereColumn('seen.employee_id', 'employees.id')->where('seen.company_id', $batch->company_id)
                            ->where('seen.batch_id', $batch->id)->where('seen.outcome', 'imported');
                    })->count();
                $this->event($batch, 'completed', $counts);
            } catch (\Throwable $exception) {
                $code = $exception instanceof ValidationException ? 'invalid_file'
                    : ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'Stored balance file')
                        ? 'stored_file_unavailable' : 'processing_failed');
                $this->event($batch, 'failed', errorCode: $code);
                throw $exception;
            }
        });
    }

    private function storeChunk(Company $company, BalanceBatch $batch, array $chunk, PreviewKeys $keys): void
    {
        DB::transaction(function () use ($company, $batch, $chunk, $keys): void {
            $codes = array_values(array_unique(array_filter(array_map(
                fn (array $row): ?string => $row['values']['employee_code'], $chunk,
            ))));
            $employees = DB::table('employees')->where('company_id', $company->id)->whereIn('employee_code', $codes)
                ->pluck('id', 'employee_code');
            $priorRows = DB::table('leave_balance_rows')->where('company_id', $company->id)->where('batch_id', $batch->id)
                ->whereIn('row_number', array_column($chunk, 'number'))->get()->keyBy('row_number');
            $now = now();
            $results = [];
            $balances = [];
            foreach ($chunk as $row) {
                $code = $row['values']['employee_code'];
                $employeeId = $code === null ? null : $employees->get($code);
                $reason = $row['errors'][0] ?? null;
                $prior = $priorRows->get($row['number']);
                if ($reason !== null) {
                    $outcome = 'invalid';
                } elseif ($keys->add('balance', [hash('sha256', $code)]) === []) {
                    $outcome = 'duplicate';
                } elseif ($employeeId === null) {
                    $outcome = 'unknown';
                } else {
                    $outcome = 'imported';
                }
                if ($prior !== null) {
                    $outcome = $prior->outcome;
                    $employeeId = $prior->employee_id;
                }
                $results[] = ['company_id' => $company->id, 'batch_id' => $batch->id,
                    'employee_id' => $employeeId, 'row_number' => $row['number'],
                    'employee_code' => $code, 'outcome' => $outcome, 'reason_code' => $reason, 'created_at' => $now];
                if ($outcome !== 'imported') {
                    continue;
                }
                $values = $row['values'];
                $monthly = [];
                for ($month = 1; $month <= 12; $month++) {
                    $field = sprintf('used_%02d', $month);
                    if ($values[$field] !== null) {
                        $monthly[sprintf('%02d', $month)] = $values[$field];
                    }
                }
                $balances[] = ['company_id' => $company->id, 'batch_id' => $batch->id,
                    'employee_id' => $employeeId, 'balance_date' => $batch->balance_date->toDateString(),
                    'balance_year' => $batch->balance_year, 'remaining_days' => $values['remaining_days'],
                    'carried_over_days' => $values['carried_over_days'], 'entitlement_days' => $values['entitlement_days'],
                    'used_days' => $values['used_days'], 'monthly_used' => $monthly === [] ? null : json_encode($monthly, JSON_THROW_ON_ERROR),
                    'source_row' => $row['number'], 'created_at' => $now];
            }
            DB::table('leave_balance_rows')->insertOrIgnore($results);
            if ($balances !== []) {
                DB::table('leave_balances')->insertOrIgnore($balances);
            }
        });
    }

    private function latestState(BalanceBatch $batch): ?string
    {
        return BalanceBatchEvent::query()->where('batch_id', $batch->id)->orderByDesc('sequence')->value('state');
    }

    private function event(BalanceBatch $batch, string $state, ?array $counts = null, ?string $errorCode = null): void
    {
        DB::transaction(function () use ($batch, $state, $counts, $errorCode): void {
            DB::table('leave_balance_batches')->where('company_id', $batch->company_id)->where('id', $batch->id)->lockForUpdate()->first();
            $sequence = (int) BalanceBatchEvent::query()->where('batch_id', $batch->id)->max('sequence') + 1;
            BalanceBatchEvent::create(['company_id' => $batch->company_id, 'batch_id' => $batch->id,
                'sequence' => $sequence, 'state' => $state, 'counts' => $counts, 'error_code' => $errorCode]);
        });
    }
}
