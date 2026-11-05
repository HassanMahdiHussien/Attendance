<?php

namespace App\Console\Commands;

use App\Jobs\Leave\ReadLeaveDescriptionsJob;
use App\Models\Company;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportRow;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestVersion;
use App\Services\AuditLogger;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\LeaveTypes;
use App\Services\Leave\ReconciliationTriggers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BackfillLeaveDescriptions extends Command
{
    protected $signature = 'attendance:leave-descriptions-backfill {--company= : Company ID}';

    protected $description = 'Append leave description and record type corrections from stored report files';

    public function handle(LeaveFile $file, LeaveTypes $types, AuditLogger $audit, ReconciliationTriggers $triggers): int
    {
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT);
        if ($companyId === false || $companyId === null) {
            $this->error('Pass a valid --company ID.');

            return self::FAILURE;
        }
        $company = Company::findOrFail($companyId);
        $changed = 0;
        app(TenantContext::class)->runForCompany($company, function () use ($company, $file, $types, $audit, $triggers, &$changed): void {
            ImportBatch::query()->where('company_id', $company->id)->orderBy('id')->each(function (ImportBatch $batch) use ($company, $file, $types, $audit, $triggers, &$changed): void {
                $path = Storage::disk('local')->path($batch->file_path);
                if (! is_file($path) || hash_file('sha256', $path) !== $batch->file_sha256) {
                    $this->warn('Skipping batch '.$batch->id.': stored file unavailable or changed.');

                    return;
                }
                $sheet = $file->inspect($path, $batch->file_name);
                $suggested = $file->suggest($sheet['headers']);
                if (! in_array('description', $suggested, true) && ! in_array('record_type', $suggested, true)) {
                    return;
                }
                $mapping = $batch->mapping;
                foreach ($suggested as $index => $field) {
                    if (in_array($field, ['description', 'record_type'], true)) {
                        $mapping[$index] = $field;
                    }
                }
                $sourceRows = ImportRow::query()->where('batch_id', $batch->id)->whereNotNull('request_id')
                    ->whereJsonDoesntContain('errors', 'duplicate_in_file')
                    ->pluck('request_id', 'row_number');
                $employeeIds = [];
                DB::transaction(function () use ($company, $batch, $file, $types, $audit, $triggers, $path, $mapping, $sourceRows, &$changed, &$employeeIds): void {
                    Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                    $nextRow = (int) RequestVersion::query()->where('batch_id', $batch->id)->max('row_number') + 1;
                    foreach ($file->rows($path, $mapping, $batch->file_name) as $row) {
                        $requestId = $sourceRows->get($row['number']);
                        if ($requestId === null) {
                            continue;
                        }
                        $request = LeaveRequest::query()->with('currentVersion')->whereKey($requestId)->lockForUpdate()->first();
                        if ($request === null || $request->currentVersion?->batch_id !== $batch->id) {
                            continue;
                        }
                        $before = $request->currentVersion->values;
                        $beforeVersionId = $request->currentVersion->id;
                        $after = $before;
                        foreach (['description', 'record_type'] as $field) {
                            if (array_key_exists($field, $row['values'])) {
                                $after[$field] = $row['values'][$field];
                            }
                        }
                        if (array_key_exists('record_type', $row['values'])) {
                            $after['type_name'] = $row['values']['type_name'];
                            $after['flags'] = $row['errors'];
                        }
                        $typeChanged = false;
                        if ($row['values']['type_name'] !== '' && $request->leaveType?->normalized_key !== $types->key(
                            $row['values']['type_name'], $row['values']['other_type_name']
                        )) {
                            $type = $types->findOrSeed($company, $row['values']['type_name'], $row['values']['other_type_name']);
                            $after['leave_type_id'] = $type->id;
                            $typeChanged = (int) $request->leave_type_id !== (int) $type->id;
                        }
                        if ($after == $before) {
                            continue;
                        }
                        $version = RequestVersion::create([
                            'company_id' => $company->id, 'request_id' => $request->id, 'batch_id' => $batch->id,
                            'row_number' => $nextRow++, 'snapshot_at' => $request->currentVersion->snapshot_at,
                            'values' => $after, 'raw_values' => $request->currentVersion->raw_values,
                        ]);
                        $request->update(['current_version_id' => $version->id,
                            'leave_type_id' => $after['leave_type_id'] ?? $request->leave_type_id,
                            'flags' => array_values(array_unique(array_merge(
                                array_diff($request->flags ?? [], $before['flags'] ?? []), $after['flags'] ?? $before['flags'] ?? []
                            )))]);
                        $audit->log('leave.description.backfilled', $request, [
                            'before_version_id' => $beforeVersionId,
                            'after_version_id' => $version->id, 'source_row' => $row['number'],
                            'type_changed' => $typeChanged,
                        ]);
                        if ($typeChanged && $request->employee_id !== null) {
                            $employeeIds[] = $request->employee_id;
                        }
                        $changed++;
                    }
                    $triggers->dispatch($company->id, $employeeIds, 'description_backfill');
                });
            });
        });
        $this->info('Backfilled '.$changed.' requests.');
        ReadLeaveDescriptionsJob::dispatch($company->id);

        return self::SUCCESS;
    }
}
