<?php

namespace App\Jobs\Leave;

use App\Models\Attendance\DataIssue;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use App\Models\Leave\ImportRow;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestVersion;
use App\Models\User;
use App\Services\Attendance\DataIssues;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\LeaveTypes;
use App\Services\Leave\ReconciliationTriggers;
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

class ImportLeaveJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public int $batchId) {}

    public function uniqueId(): string
    {
        return (string) $this->batchId;
    }

    public function handle(LeaveFile $file, LeaveTypes $types, DataIssues $issues): void
    {
        $companyId = DB::table('leave_import_batches')->where('id', $this->batchId)->value('company_id');
        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $file, $types, $issues): void {
            $batch = ImportBatch::query()->findOrFail($this->batchId);
            if ($this->latestState($batch) === 'completed') {
                return;
            }
            try {
                $this->event($batch, 'processing');
                $path = Storage::disk('local')->path($batch->file_path);
                if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_sha256) {
                    throw new RuntimeException('Stored leave file changed or is missing.');
                }
                $sheet = $file->inspect($path, $batch->file_name);
                $file->validateMapping($batch->mapping, $sheet['headers']);
                $chunk = [];
                foreach ($file->rows($path, $batch->mapping, $batch->file_name) as $row) {
                    $chunk[] = $row;
                    if (count($chunk) === 1000) {
                        $this->storeChunk($company, $batch, $chunk, $types, $issues);
                        $chunk = [];
                    }
                }
                if ($chunk !== []) {
                    $this->storeChunk($company, $batch, $chunk, $types, $issues);
                }
                if ($batch->scope === 'full') {
                    $this->missingFromFull($company, $batch, $issues);
                }
                $employeeIds = DB::table('leave_import_rows as rows')
                    ->join('leave_requests as requests', function ($join): void {
                        $join->on('requests.id', '=', 'rows.request_id')->on('requests.company_id', '=', 'rows.company_id');
                    })
                    ->where('rows.company_id', $company->id)->where('rows.batch_id', $batch->id)
                    ->whereNotNull('requests.employee_id')->distinct()->pluck('requests.employee_id')->all();
                $priorOwners = DB::table('leave_import_rows as rows')
                    ->join('leave_request_versions as versions', function ($join): void {
                        $join->on('versions.request_id', '=', 'rows.request_id')->on('versions.company_id', '=', 'rows.company_id');
                    })
                    ->where('rows.company_id', $company->id)->where('rows.batch_id', $batch->id)
                    ->selectRaw("DISTINCT CAST(JSON_UNQUOTE(JSON_EXTRACT(`versions`.`values`, '$.employee_id')) AS UNSIGNED) AS employee_id")
                    ->pluck('employee_id')->filter()->all();
                $employeeIds = array_values(array_unique(array_merge($employeeIds, $priorOwners)));
                app(ReconciliationTriggers::class)->dispatch($company->id, $employeeIds, 'import');
                $this->event($batch, 'completed', [
                    'rows' => ImportRow::query()->where('batch_id', $batch->id)->count(),
                    'requests' => ImportRow::query()->where('batch_id', $batch->id)->whereNotNull('request_id')->distinct()->count('request_id'),
                    'errors' => ImportRow::query()->where('batch_id', $batch->id)->whereRaw('JSON_LENGTH(`errors`) > 0')->count(),
                ]);
            } catch (\Throwable $exception) {
                $code = $exception instanceof ValidationException ? 'invalid_file'
                    : ($exception instanceof RuntimeException && str_starts_with($exception->getMessage(), 'Stored leave file')
                        ? 'stored_file_unavailable' : 'processing_failed');
                $this->event($batch, 'failed', errorCode: $code);
                throw $exception;
            }
        });
    }

    private function storeChunk(Company $company, ImportBatch $batch, array $chunk, LeaveTypes $types, DataIssues $issues): void
    {
        DB::transaction(function () use ($company, $batch, $chunk, $types, $issues): void {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $codes = array_values(array_unique(array_filter(
                array_column(array_column($chunk, 'values'), 'owner_code'),
                fn (string $code): bool => $code !== '',
            )));
            $employees = Employee::query()->where('company_id', $company->id)->whereIn('employee_code', $codes)
                ->pluck('id', 'employee_code');
            foreach ($chunk as $row) {
                if (ImportRow::query()->where('batch_id', $batch->id)->where('row_number', $row['number'])->exists()) {
                    continue;
                }
                $values = $row['values'];
                $request = null;
                $errors = $row['errors'];
                if ($values['external_id'] !== '' && $values['owner_code'] !== '' && mb_strlen($values['external_id']) <= 255
                    && mb_strlen($values['owner_code']) <= 255) {
                    $request = LeaveRequest::query()->where('company_id', $company->id)
                        ->where('source', 'company_report')->where('external_id', $values['external_id'])
                        ->lockForUpdate()->first();
                    if ($request === null) {
                        $request = LeaveRequest::create([
                            'company_id' => $company->id, 'source' => 'company_report',
                            'external_id' => $values['external_id'], 'owner_code' => $values['owner_code'],
                            'employee_id' => $employees->get($values['owner_code']), 'status' => $values['status'],
                            'raw_status' => $values['raw_status'], 'flags' => [],
                        ]);
                    }
                    $firstInBatch = ! ImportRow::query()->where('batch_id', $batch->id)
                        ->where('request_id', $request->id)->exists();
                    if ($firstInBatch) {
                        $type = $values['type_name'] === '' ? null : $types->findOrSeed($company, $values['type_name'], $values['other_type_name']);
                        $employeeId = $employees->get($values['owner_code']);
                        if ($employeeId === null) {
                            $errors[] = 'unknown_employee';
                            $issues->open($company, 'leave_unknown_employee', 'leave_request', 'request:'.$request->id);
                        }
                        if (in_array('invalid_dates', $errors, true)) {
                            $issues->open($company, 'leave_invalid_dates', 'leave_request', 'request:'.$request->id);
                        }
                        if (in_array('unknown_status', $errors, true)) {
                            $issues->open($company, 'leave_unknown_status', 'leave_request', 'request:'.$request->id);
                        }
                        if (in_array('invalid_duration', $errors, true) || in_array('missing_duration', $errors, true)
                            || in_array('duration_mismatch', $errors, true)) {
                            $issues->open($company, 'leave_invalid_duration', 'leave_request', 'request:'.$request->id);
                        }
                        $parsed = [
                            'employee_id' => $employeeId, 'owner_code' => $values['owner_code'],
                            'leave_type_id' => $type?->id, 'status' => $values['status'],
                            'raw_status' => $values['raw_status'], 'rejected_on' => $values['rejected_on'],
                            'start_date' => $values['start_date'], 'end_date' => $values['end_date'],
                            'duration_days' => $values['duration_days'], 'approver_name' => $values['approver_name'],
                            'created_on' => $values['created_on'], 'flags' => array_values(array_unique($errors)),
                        ];
                        $this->observe($request, $batch, $row, $parsed);
                        $this->resolveReappearance($company, $request, $batch);
                    } else {
                        $errors[] = 'duplicate_in_file';
                    }
                } else {
                    $errors[] = 'missing_identity';
                }
                DB::table('leave_import_rows')->insertOrIgnore([
                    'company_id' => $company->id, 'batch_id' => $batch->id,
                    'request_id' => $request?->id, 'row_number' => $row['number'],
                    'raw_values' => json_encode($row['raw'], JSON_THROW_ON_ERROR),
                    'errors' => json_encode(array_values(array_unique($errors)), JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
                $rowIssue = $request === null;
                foreach ($errors as $code) {
                    $rowIssue = $rowIssue || $code === 'column_count'
                        || str_ends_with($code, '_required') || str_ends_with($code, '_too_long');
                }
                if ($rowIssue && $errors !== []) {
                    $issues->open($company, 'leave_import_row_error', 'batch_row', $batch->id.':'.$row['number'], details: ['codes' => $errors]);
                }
            }
            $this->event($batch, 'processing', ['through_row' => $chunk[array_key_last($chunk)]['number']]);
        });
    }

    private function observe(LeaveRequest $request, ImportBatch $batch, array $row, array $parsed): void
    {
        $current = $request->currentVersion;
        $same = $current !== null && $this->canonical($current->values) === $this->canonical($parsed)
            && $this->canonical($current->raw_values) === $this->canonical($row['raw']);
        $version = $same ? null : RequestVersion::create([
            'company_id' => $batch->company_id, 'request_id' => $request->id,
            'batch_id' => $batch->id, 'row_number' => $row['number'],
            'snapshot_at' => $batch->snapshot_at, 'values' => $parsed, 'raw_values' => $row['raw'],
        ]);
        $newer = $request->latest_snapshot_at === null
            || $batch->snapshot_at->greaterThan($request->latest_snapshot_at)
            || ($batch->snapshot_at->equalTo($request->latest_snapshot_at) && $batch->id > $request->latest_batch_id);
        if ($newer) {
            $request->fill($parsed);
            $request->latest_snapshot_at = $batch->snapshot_at;
            $request->latest_batch_id = $batch->id;
            if ($version !== null) {
                $request->current_version_id = $version->id;
            }
            $request->save();
        }
    }

    private function canonical(array $values): string
    {
        ksort($values);

        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    private function missingFromFull(Company $company, ImportBatch $batch, DataIssues $issues): void
    {
        $seen = DB::table('leave_import_rows')->where('company_id', $company->id)
            ->where('batch_id', $batch->id)->whereNotNull('request_id')->select('request_id');
        LeaveRequest::query()->where('company_id', $company->id)->where('source', 'company_report')
            ->whereBetween('start_date', [$batch->period_from->toDateString(), $batch->period_to->toDateString()])
            ->where('latest_snapshot_at', '<=', $batch->snapshot_at)
            ->whereNotIn('id', $seen)->orderBy('id')->chunkById(1000, function ($requests) use ($company, $issues): void {
                foreach ($requests as $request) {
                    $issues->open($company, 'leave_request_missing', 'leave_request', 'request:'.$request->id);
                }
            });
    }

    private function resolveReappearance(Company $company, LeaveRequest $request, ImportBatch $batch): void
    {
        $issue = DataIssue::query()->where('company_id', $company->id)->where('type', 'leave_request_missing')
            ->where('open_key', 'leave_request_missing|leave_request|request:'.$request->id)->first();
        $actor = $batch->uploaded_by === null ? null : User::find($batch->uploaded_by);
        if ($issue !== null && $actor !== null) {
            app(DataIssues::class)->resolve($issue, $actor, __('messages.leave_import.request_reappeared'));
        }
    }

    private function latestState(ImportBatch $batch): ?string
    {
        return ImportBatchEvent::query()->where('batch_id', $batch->id)->orderByDesc('sequence')->value('state');
    }

    private function event(ImportBatch $batch, string $state, ?array $counts = null, ?string $errorCode = null): void
    {
        DB::transaction(function () use ($batch, $state, $counts, $errorCode): void {
            DB::table('leave_import_batches')->where('company_id', $batch->company_id)->where('id', $batch->id)->lockForUpdate()->first();
            $sequence = (int) ImportBatchEvent::query()->where('batch_id', $batch->id)->max('sequence') + 1;
            ImportBatchEvent::create([
                'company_id' => $batch->company_id, 'batch_id' => $batch->id,
                'sequence' => $sequence, 'state' => $state, 'counts' => $counts, 'error_code' => $errorCode,
            ]);
        });
    }
}
