<?php

namespace App\Services\Attendance\FollowUp;

use App\Models\Attendance\CaseEvent;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\FollowUpCase;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDecision;
use App\Services\Attendance\DataIssues;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class LeaveEntryCases
{
    public function __construct(private AuditLogger $audit, private DataIssues $issues) {}

    public function syncEmployee(Company $company, Employee $employee): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id && $employee->company_id === $company->id, 403);
        $cutoff = CarbonImmutable::today('Asia/Baghdad')
            ->subDays((int) config('attendance.leave_entry.notify_window_days', 30))->toDateString();
        $requests = LeaveRequest::query()->with('leaveType')->where('company_id', $company->id)
            ->where('employee_id', $employee->id)->where('source', 'company_report')
            ->where(function ($query) use ($cutoff): void {
                $query->where(fn ($drafts) => $drafts->whereRaw('LOWER(raw_status) = ?', ['leave entry'])
                    ->where('status', 'draft')->whereNull('rejected_on')->whereDate('start_date', '>=', $cutoff))
                    ->orWhereIn('id', FollowUpCase::query()->where('case_kind', 'leave_entry')->select('leave_request_id'));
            })->get()->keyBy('id');
        $cases = FollowUpCase::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->where('case_kind', 'leave_entry')->lockForUpdate()->get()->keyBy('leave_request_id');
        $manager = $employee->manager_employee_id === null ? null : Employee::query()->find($employee->manager_employee_id);
        foreach ($requests as $request) {
            $old = $cases->get($request->id);
            $cancelled = RequestDecision::query()->where('company_id', $company->id)
                ->where('request_id', $request->id)->where('decision', 'cancel')->get()
                ->contains(fn (RequestDecision $decision): bool => ! RequestDecision::query()
                    ->where('request_id', $request->id)->where('decision', 'withdraw')
                    ->where('values->decision_id', $decision->id)->exists());
            $active = $request->status === 'draft' && mb_strtolower(trim((string) $request->raw_status)) === 'leave entry'
                && $request->rejected_on === null && $request->start_date !== null
                && $request->start_date->toDateString() >= $cutoff && ! $cancelled;
            if (! $active && $old === null) {
                continue;
            }
            $address = $manager?->email;
            if (blank($address) || $address === $employee->email) {
                $address = $employee->fallback_notification_email;
            }
            $available = filled($address) && $address !== $employee->email;
            $routes = $active ? [[
                'key' => 'manager', 'kind' => 'manager', 'request_id' => $request->id,
                'external_id' => $request->external_id, 'approver_text' => null, 'approver_key' => null,
                'recipient_employee_id' => $manager?->id, 'recipient_available' => $available,
                'items' => [['type' => 'leave_entry', 'minutes' => null]],
            ]] : [];
            $items = $active ? [['type' => 'leave_entry', 'minutes' => null]] : [];
            $manualUnchanged = $active && $old?->manual_closed_items !== null
                && $old->manual_closed_items == $items;
            if ($manualUnchanged) {
                $routes = $old->routes;
            }
            $state = ! $active || $manualUnchanged ? 'closed' : (! $available ? 'waiting_hr'
                : ($old?->state === 'waiting_manager' && $old->items == $items && $old->routes == $routes
                    ? 'waiting_manager' : 'open'));
            $values = [
                'state' => $state,
                'items' => $items,
                'routes' => $routes, 'request_ids' => [$request->id],
                'closure_reason' => $manualUnchanged ? $old->closure_reason : ($active ? null : 'leave_entry_changed'),
                'closure_source' => $manualUnchanged ? $old->closure_source : ($active ? null : ['kind' => 'leave_report']),
                'closed_at' => $state === 'closed' ? ($old?->closed_at ?? now()) : null,
                'manual_closed_items' => $manualUnchanged ? $old->manual_closed_items : null,
                'manual_closed_by' => $manualUnchanged ? $old->manual_closed_by : null,
                'manual_closed_at' => $manualUnchanged ? $old->manual_closed_at : null,
            ];
            if ($old === null) {
                $case = FollowUpCase::create($values + [
                    'company_id' => $company->id, 'employee_id' => $employee->id,
                    'work_date' => $request->start_date, 'day_id' => null,
                    'case_kind' => 'leave_entry',
                    'leave_request_id' => $request->id,
                ]);
                $this->event($case, 'opened', null, $values);
            } elseif ($old->state !== $values['state'] || $old->items != $values['items'] || $old->routes != $values['routes']
                || $old->manual_closed_items != $values['manual_closed_items']) {
                $before = ['state' => $old->state, 'items' => $old->items, 'routes' => $old->routes];
                $old->update($values);
                $this->event($old, $active ? 'reopened' : 'closed', $before, $values);
            }
            if ($active && ! $available && ! $manualUnchanged) {
                $subject = $manager === null ? 'employee:'.$employee->id : 'manager:'.$manager->id;
                $this->issues->open($company, 'notification_recipient_missing', $manager === null ? 'employee' : 'manager', $subject);
            }
        }
        $this->resolveUnusedRecipientIssue($company, 'employee', 'employee:'.$employee->id);
        if ($manager !== null) {
            $this->resolveUnusedRecipientIssue($company, 'manager', 'manager:'.$manager->id);
        }
    }

    public function syncAll(Company $company): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $ids = LeaveRequest::query()->where('company_id', $company->id)->whereRaw('LOWER(raw_status) = ?', ['leave entry'])
            ->whereNotNull('employee_id')->distinct()->pluck('employee_id')
            ->merge(FollowUpCase::query()->where('case_kind', 'leave_entry')->distinct()->pluck('employee_id'))
            ->unique();
        foreach ($ids as $id) {
            DB::transaction(function () use ($company, $id): void {
                $employee = Employee::query()->whereKey($id)->lockForUpdate()->first();
                if ($employee !== null) {
                    $this->syncEmployee($company, $employee);
                }
            });
        }
    }

    private function event(FollowUpCase $case, string $action, ?array $before, array $after): void
    {
        CaseEvent::create(['company_id' => $case->company_id, 'case_id' => $case->id,
            'event' => $action, 'before' => $before, 'after' => $after,
            'source' => 'leave_entry', 'created_at' => now()]);
        $this->audit->log('attendance.case.'.$action, $case, [
            'case_kind' => 'leave_entry', 'before' => $before['state'] ?? null,
            'after' => $after['state'], 'request_id' => $case->leave_request_id,
        ]);
    }

    private function resolveUnusedRecipientIssue(Company $company, string $subjectType, string $subjectKey): void
    {
        $issue = DataIssue::query()->where('company_id', $company->id)
            ->where('open_key', 'notification_recipient_missing|'.$subjectType.'|'.$subjectKey)->first();
        if ($issue === null) {
            return;
        }
        $cases = FollowUpCase::query()->where('company_id', $company->id)->where('state', '!=', 'closed');
        if ($subjectType === 'employee') {
            $cases->where('employee_id', (int) substr($subjectKey, 9));
        } else {
            $cases->whereJsonContains('routes', ['kind' => 'manager',
                'recipient_employee_id' => (int) substr($subjectKey, 8)]);
        }
        $needed = $cases->get(['routes'])->contains(function (FollowUpCase $case) use ($subjectType, $subjectKey): bool {
            return collect($case->routes)->contains(function (array $route) use ($subjectType, $subjectKey): bool {
                return $route['kind'] === 'manager' && ! ($route['recipient_available'] ?? false)
                    && ($subjectType === 'employee' ? ($route['recipient_employee_id'] ?? null) === null
                        : (int) ($route['recipient_employee_id'] ?? 0) === (int) substr($subjectKey, 8));
            });
        });
        if (! $needed) {
            $issue->update(['status' => 'resolved', 'open_key' => null, 'resolved_at' => now(),
                'note' => __('messages.follow_up.issue_resolved')]);
            $this->audit->log('attendance.issue.resolved', $issue, ['type' => $issue->type]);
        }
    }
}
