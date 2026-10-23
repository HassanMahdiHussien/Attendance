<?php

namespace App\Services\Leave;

use App\Events\EmployeeVersionChanged;
use App\Models\Attendance\DataIssue;
use App\Models\Company;
use App\Models\Leave\LeaveRequest;
use App\Services\Attendance\DataIssues;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class UnknownEmployeeIssues
{
    public function __construct(private DataIssues $issues, private AuditLogger $audit, private ReconciliationTriggers $triggers) {}

    public function refreshForBatch(Company $company, int $batchId): void
    {
        DB::table('leave_import_rows as rows')->join('leave_requests as requests', function ($join): void {
            $join->on('requests.id', '=', 'rows.request_id')->on('requests.company_id', '=', 'rows.company_id');
        })->where('rows.company_id', $company->id)->where('rows.batch_id', $batchId)
            ->whereNotNull('rows.request_id')->whereNull('requests.employee_id')
            ->distinct()->orderBy('requests.owner_code')
            ->pluck('requests.owner_code')->each(fn (string $code) => $this->refresh($company, $code));
    }

    public function refresh(Company $company, string $code): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $requests = LeaveRequest::query()->where('company_id', $company->id)->where('owner_code', $code)
            ->whereNull('employee_id');
        $count = (clone $requests)->count();
        LeaveRequest::query()->where('company_id', $company->id)->where('owner_code', $code)
            ->select('id')->orderBy('id')->chunkById(500, function ($rows) use ($company): void {
                $keys = $rows->map(fn (LeaveRequest $request): string => 'request:'.$request->id)->all();
                DataIssue::query()->where('company_id', $company->id)->where('type', 'leave_unknown_employee')
                    ->where('subject_type', 'leave_request')->whereIn('subject_key', $keys)
                    ->where('status', 'open')->get()->each(function (DataIssue $issue): void {
                        $issue->update(['status' => 'resolved', 'open_key' => null,
                            'note' => __('messages.leave_import.unknown_issue_grouped'), 'resolved_at' => now()]);
                        $this->audit->log('leave.issue.resolved', $issue, ['type' => $issue->type, 'subject_key' => $issue->subject_key]);
                    });
            });
        $key = 'leave_unknown_employee|employee_code|owner:'.$code;
        if ($count === 0) {
            $issue = DataIssue::query()->where('company_id', $company->id)->where('open_key', $key)->first();
            if ($issue !== null) {
                $issue->update(['status' => 'resolved', 'open_key' => null,
                    'note' => __('messages.leave_import.employee_found'), 'resolved_at' => now()]);
                $this->audit->log('leave.issue.resolved', $issue, ['type' => $issue->type, 'subject_key' => $issue->subject_key]);
            }

            return;
        }
        $forms = (clone $requests)->orderBy('id')->limit(20)->pluck('external_id')->all();
        $details = ['request_count' => $count, 'form_numbers' => $forms];
        $issue = $this->issues->open($company, 'leave_unknown_employee', 'employee_code', 'owner:'.$code, details: $details);
        if ($issue->details !== $details) {
            $issue->update(['details' => $details]);
            $this->audit->log('leave.issue.updated', $issue, ['type' => $issue->type,
                'subject_key' => $issue->subject_key, 'details' => $details]);
        }
    }

    public function employeeVersionChanged(EmployeeVersionChanged $event): void
    {
        $companyId = (int) $event->employee->company_id;
        $employeeId = (int) $event->employee->id;
        $code = (string) $event->employee->employee_code;
        DB::afterCommit(function () use ($companyId, $employeeId, $code): void {
            $company = Company::findOrFail($companyId);
            app(TenantContext::class)->runForCompany($company, function () use ($company, $employeeId, $code): void {
                $linked = DB::transaction(function () use ($company, $employeeId, $code): bool {
                    $requests = LeaveRequest::query()->where('company_id', $company->id)->where('owner_code', $code)
                        ->whereNull('employee_id')->lockForUpdate()->get();
                    foreach ($requests as $request) {
                        $request->update(['employee_id' => $employeeId,
                            'flags' => array_values(array_diff($request->flags ?? [], ['unknown_employee']))]);
                        $this->audit->log('leave.request.employee_linked', $request, [
                            'before' => ['employee_id' => null], 'after' => ['employee_id' => $employeeId],
                            'owner_code' => $code, 'form_number' => $request->external_id,
                        ]);
                    }
                    $this->refresh($company, $code);

                    return $requests->isNotEmpty();
                });
                if ($linked) {
                    $this->triggers->dispatch($company->id, [$employeeId], 'employee_linked');
                }
            });
        });
    }
}
