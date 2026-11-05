<?php

namespace App\Console\Commands;

use App\Models\Attendance\DataIssue;
use App\Models\Company;
use App\Models\Leave\ImportBatchEvent;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestVersion;
use App\Services\AuditLogger;
use App\Services\Leave\LeaveStatus;
use App\Services\Leave\ReconciliationTriggers;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshLeaveStatuses extends Command
{
    protected $signature = 'attendance:leave-status-refresh {--company= : Company ID}';

    protected $description = 'Append corrected statuses for previously imported leave forms';

    private int $changed = 0;

    private int $resolved = 0;

    private int $skipped = 0;

    public function handle(LeaveStatus $statuses, AuditLogger $audit, ReconciliationTriggers $triggers): int
    {
        $companyId = filter_var($this->option('company'), FILTER_VALIDATE_INT);
        if ($companyId === false || $companyId === null) {
            $this->error(__('messages.leave_import.status_refresh_company_required'));

            return self::FAILURE;
        }

        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $statuses, $audit, $triggers): void {
            LeaveRequest::query()->where('company_id', $company->id)->where('source', 'company_report')
                ->whereNotNull('current_version_id')->orderBy('id')->chunkById(500, function ($requests) use ($company, $statuses, $audit, $triggers): void {
                    DB::transaction(function () use ($company, $requests, $statuses, $audit, $triggers): void {
                        Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
                        $nextRows = [];
                        $finished = [];
                        $employeeIds = [];
                        foreach ($requests as $candidate) {
                            $request = LeaveRequest::query()->with('currentVersion')->whereKey($candidate->id)->lockForUpdate()->firstOrFail();
                            $derived = $statuses->derive($request->raw_status ?? '', $request->rejected_on?->toDateString());
                            if ($derived['unknown'] || $request->currentVersion === null) {
                                continue;
                            }

                            // The parser excuses a cancelled form from a duration, so that flag is stale on old rows too.
                            $stale = $derived['status'] === 'cancelled' ? ['unknown_status', 'missing_duration'] : ['unknown_status'];
                            $current = $request->currentVersion;
                            $statusChanged = $derived['status'] !== $request->status;
                            if ($statusChanged || array_intersect($stale, $current->values['flags'] ?? []) !== []) {
                                $batchId = $current->batch_id;
                                // Row numbers past the file's rows are free only once the import can no longer resume.
                                $finished[$batchId] ??= ImportBatchEvent::query()->where('batch_id', $batchId)
                                    ->orderByDesc('sequence')->value('state') === 'completed';
                                if (! $finished[$batchId]) {
                                    $this->skipped++;

                                    continue;
                                }
                                $values = $current->values;
                                $values['status'] = $derived['status'];
                                $values['flags'] = array_values(array_diff($values['flags'] ?? [], $stale));
                                $nextRows[$batchId] ??= (int) RequestVersion::query()->where('batch_id', $batchId)->max('row_number') + 1;
                                $version = RequestVersion::create([
                                    'company_id' => $company->id, 'request_id' => $request->id, 'batch_id' => $batchId,
                                    'row_number' => $nextRows[$batchId]++, 'snapshot_at' => $current->snapshot_at,
                                    'values' => $values, 'raw_values' => $current->raw_values,
                                ]);
                                $request->update([
                                    'status' => $derived['status'], 'current_version_id' => $version->id,
                                    'flags' => array_values(array_diff($request->flags ?? [], $stale)),
                                ]);
                                $audit->log('leave.status.refreshed', $request, [
                                    'before_version_id' => $current->id, 'after_version_id' => $version->id,
                                    'before_status' => $current->values['status'] ?? null, 'after_status' => $derived['status'],
                                ]);
                                if ($request->employee_id !== null && $statusChanged) {
                                    $employeeIds[] = $request->employee_id;
                                }
                                $this->changed++;
                            }

                            $this->resolve($company, $request, 'leave_unknown_status', $audit);
                            // This issue also covers a bad duration, which stays HR's to see.
                            if ($derived['status'] === 'cancelled'
                                && array_intersect(['invalid_duration', 'duration_mismatch'], $request->flags ?? []) === []) {
                                $this->resolve($company, $request, 'leave_invalid_duration', $audit);
                            }
                        }
                        $triggers->dispatch($company->id, $employeeIds, 'status_refresh');
                    });
                });
        });

        $this->line(__('messages.leave_import.status_refresh_counts', [
            'changed' => $this->changed, 'resolved' => $this->resolved, 'skipped' => $this->skipped,
        ]));

        return self::SUCCESS;
    }

    // DataIssues::resolve requires a user; this runs from the console with none.
    private function resolve(Company $company, LeaveRequest $request, string $type, AuditLogger $audit): void
    {
        $issue = DataIssue::query()->where('company_id', $company->id)
            ->where('open_key', $type.'|leave_request|request:'.$request->id)->lockForUpdate()->first();
        if ($issue === null) {
            return;
        }
        $issue->update(['status' => 'resolved', 'open_key' => null, 'resolved_at' => now(),
            'note' => __('messages.leave_import.status_refresh_issue_resolved')]);
        $audit->log('attendance.issue.resolved', $issue, [
            'type' => $issue->type, 'subject_key' => $issue->subject_key,
            'before' => 'open', 'after' => 'resolved',
        ]);
        $this->resolved++;
    }
}
