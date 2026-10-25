<?php

namespace App\Services\Attendance\FollowUp;

use App\Events\EmployeeVersionChanged;
use App\Models\Attendance\ApproverMapping;
use App\Models\Attendance\CaseEvent;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\FollowUpCase;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\RequestDay;
use App\Models\User;
use App\Services\Attendance\DataIssues;
use App\Services\AuditLogger;
use App\Services\Leave\DayReconciliation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CaseSync
{
    private const EXCLUDED = [
        'pending', 'device_outage', 'not_employed', 'rest_day', 'rest_day_worked',
        'holiday', 'holiday_worked', 'pattern_missing', 'shift_unresolved',
    ];

    public function __construct(
        private DayReconciliation $reconciliation,
        private DataIssues $issues,
        private AuditLogger $audit,
    ) {}

    public function syncLocked(Company $company, Employee $employee, string $source): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id
            && (int) $employee->company_id === (int) $company->id, 403);
        $cases = FollowUpCase::query()->where('employee_id', $employee->id)
            ->orderBy('work_date')->lockForUpdate()->get()->keyBy(fn (FollowUpCase $case): string => $case->work_date->toDateString());
        $firstDay = DB::table('attendance_days')->where('company_id', $company->id)
            ->where('employee_id', $employee->id)->whereNull('superseded_by_run_id')->min('work_date');
        $lastDay = DB::table('attendance_days')->where('company_id', $company->id)
            ->where('employee_id', $employee->id)->whereNull('superseded_by_run_id')->max('work_date');
        $dates = array_filter([$firstDay, $lastDay, $cases->keys()->first(), $cases->keys()->last()]);
        if ($dates === []) {
            return;
        }
        $from = min($dates);
        $to = max($dates);
        $days = $this->reconciliation->for($employee, $from, $to);
        $allocations = Allocation::query()->where('employee_id', $employee->id)
            ->whereIn('target_day_id', array_column($days, 'day_id'))
            ->whereNull('superseded_by_run_id')->get()->groupBy('target_day_id');
        $credits = Credit::query()->whereIn('id', $allocations->flatten()->pluck('source_credit_id')->filter())
            ->pluck('source_day_id', 'id');
        $sourceDayIds = $allocations->flatten()->pluck('source_request_day_id')->filter()
            ->merge($credits->values())->unique();
        $requestIds = RequestDay::query()->whereIn('id', $sourceDayIds)->pluck('request_id', 'id');
        $mappings = ApproverMapping::query()->where('company_id', $company->id)->get()->keyBy('approver_key');
        $affectedApprovers = [];
        $affectedManagers = [];
        foreach ($cases as $case) {
            foreach ($case->routes as $route) {
                if ($route['kind'] === 'approver') {
                    $affectedApprovers[$route['approver_key']] = true;
                }
                if ($route['kind'] === 'manager' && $route['recipient_employee_id'] !== null) {
                    $affectedManagers[$route['recipient_employee_id']] = true;
                }
            }
        }
        $allDates = array_values(array_unique(array_merge(array_keys($days), $cases->keys()->all())));
        sort($allDates);
        $managerVersions = EmployeeVersion::query()->where('employee_id', $employee->id)
            ->where('effective_from', '<=', $to)->orderBy('effective_from')->orderBy('id')->get();
        $recipientIds = $mappings->pluck('employee_id');
        foreach ($managerVersions as $version) {
            $changes = $version->changes;
            if (isset($changes['manager_employee_id'])) {
                $recipientIds->push((int) $changes['manager_employee_id']);
            }
        }
        $recipients = Employee::query()->whereIn('id', $recipientIds->unique())->get()->keyBy('id');
        $managerIndex = 0;
        $managerId = null;
        foreach ($allDates as $date) {
            while ($managerIndex < $managerVersions->count()
                && $managerVersions[$managerIndex]->effective_from->toDateString() <= $date) {
                $changes = $managerVersions[$managerIndex]->changes;
                if (array_key_exists('manager_employee_id', $changes)) {
                    $managerId = $changes['manager_employee_id'] === null ? null : (int) $changes['manager_employee_id'];
                }
                $managerIndex++;
            }
            $result = $days[$date] ?? null;
            $old = $cases->get($date);
            $items = $this->items($result);
            if ($items === [] && $old === null) {
                continue;
            }
            $manualUnchanged = $old?->manual_closed_items !== null && $old->manual_closed_items == $items && $items !== [];
            $routes = $manualUnchanged ? $old->routes : $this->routes($employee, $managerId, $items,
                $result['pending_requests'] ?? [], $mappings, $recipients);
            $dayAllocations = $allocations->get($result['day_id'] ?? 0, collect());
            $relatedRequests = collect($result['pending_requests'] ?? [])->pluck('request_id')
                ->merge($dayAllocations->map(function (Allocation $allocation) use ($credits, $requestIds): ?int {
                    $sourceDayId = $allocation->source_request_day_id
                        ?? $credits->get($allocation->source_credit_id);

                    return $requestIds->get($sourceDayId);
                }))->filter()->unique()->sort()->values()->all();
            foreach ($routes as $route) {
                if ($route['kind'] === 'approver') {
                    $affectedApprovers[$route['approver_key']] = true;
                }
                if ($route['kind'] === 'manager' && $route['recipient_employee_id'] !== null) {
                    $affectedManagers[$route['recipient_employee_id']] = true;
                }
            }
            $state = $manualUnchanged ? 'closed' : $this->state($items, $routes, $old);
            $closureSource = $state === 'closed' ? ($manualUnchanged ? $old->closure_source
                : $this->closureSource($result, $dayAllocations)) : null;
            $values = [
                'day_id' => $result['day_id'] ?? null, 'state' => $state, 'items' => $items,
                'routes' => $routes, 'request_ids' => $relatedRequests,
                'closure_reason' => $state === 'closed' ? ($manualUnchanged ? $old->closure_reason
                    : ($result === null || in_array($result['status'], self::EXCLUDED, true) ? 'reassessment' : 'reconciled')) : null,
                'closure_source' => $closureSource,
                'closed_at' => $state === 'closed' ? ($old?->state === 'closed' ? $old->closed_at : now()) : null,
                'manual_closed_items' => $manualUnchanged ? $old->manual_closed_items : null,
                'manual_closed_by' => $manualUnchanged ? $old->manual_closed_by : null,
                'manual_closed_at' => $manualUnchanged ? $old->manual_closed_at : null,
            ];
            if ($old === null) {
                $case = FollowUpCase::create($values + [
                    'company_id' => $company->id, 'employee_id' => $employee->id, 'work_date' => $date,
                ]);
                $this->event($case, 'opened', null, $this->snapshot($case), $source);
            } else {
                $before = $this->snapshot($old);
                if ($this->same($old, $values)) {
                    continue;
                }
                $old->update($values);
                $this->event($old, $this->changeEvent($before, $old), $before, $this->snapshot($old), $source);
            }
        }
        $this->refreshIssues($company, $employee, array_keys($affectedApprovers), array_keys($affectedManagers));
    }

    public function reroute(Employee $employee): void
    {
        $company = Company::findOrFail($employee->company_id);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $employee): void {
            DB::transaction(function () use ($company, $employee): void {
                $locked = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
                $this->syncLocked($company, $locked, 'routing_changed');
            });
        });
    }

    public function employeeVersionChanged(EmployeeVersionChanged $event): void
    {
        $employeeId = $event->employee->id;
        $companyId = $event->employee->company_id;
        DB::afterCommit(function () use ($employeeId, $companyId): void {
            $company = Company::findOrFail($companyId);
            app(TenantContext::class)->runForCompany($company, function () use ($employeeId): void {
                $employee = Employee::findOrFail($employeeId);
                if (FollowUpCase::query()->where('employee_id', $employeeId)->exists()) {
                    $this->reroute($employee);
                }
                $managedIds = Employee::query()->where('manager_employee_id', $employeeId)->pluck('id');
                $historicalIds = FollowUpCase::query()->where('state', '!=', 'closed')
                    ->whereJsonContains('routes', ['kind' => 'manager', 'recipient_employee_id' => $employeeId])
                    ->pluck('employee_id');
                $managedIds = $managedIds->merge($historicalIds)->unique();
                foreach ($managedIds as $managedId) {
                    if (FollowUpCase::query()->where('employee_id', $managedId)->exists()) {
                        $this->reroute(Employee::findOrFail($managedId));
                    }
                }
                foreach (ApproverMapping::query()->where('employee_id', $employeeId)->get() as $mapping) {
                    $this->rerouteApprover($mapping->approver_key);
                }
            });
        });
    }

    public function rerouteApprover(string $key): void
    {
        $ids = FollowUpCase::query()->where('state', '!=', 'closed')
            ->whereJsonContains('routes', ['approver_key' => $key])->distinct()->pluck('employee_id');
        foreach ($ids as $id) {
            $this->reroute(Employee::findOrFail($id));
        }
    }

    public function closeManually(FollowUpCase $case, string $reason, User $actor): void
    {
        abort_unless(auth()->id() === $actor->id && app(TenantContext::class)->companyId() === $case->company_id, 403);
        Gate::forUser($actor)->authorize('sendAttendanceNotifications', Company::findOrFail($case->company_id));
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('messages.follow_up.reason_required')]);
        }
        DB::transaction(function () use ($case, $reason, $actor): void {
            Employee::query()->whereKey($case->employee_id)->lockForUpdate()->firstOrFail();
            $case = FollowUpCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->state === 'closed') {
                return;
            }
            $before = $this->snapshot($case);
            $case->update([
                'state' => 'closed', 'closure_reason' => trim($reason),
                'closure_source' => ['kind' => 'manual', 'actor_id' => $actor->id],
                'closed_at' => now(), 'manual_closed_items' => $case->items,
                'manual_closed_by' => $actor->id, 'manual_closed_at' => now(),
            ]);
            $this->event($case, 'closed_manually', $before, $this->snapshot($case), 'manual', $actor, trim($reason));
        });
    }

    private function items(?array $result): array
    {
        if ($result === null || in_array($result['status'], self::EXCLUDED, true)) {
            return [];
        }
        $items = [];
        foreach (['absence', 'late', 'early', 'exit'] as $kind) {
            if (($result['remaining'][$kind] ?? 0) > 0) {
                $items[] = ['type' => $kind, 'minutes' => (int) $result['remaining'][$kind]];
            }
        }
        if ($result['status'] === 'missing_punch') {
            $items[] = ['type' => 'missing_punch', 'minutes' => null];
        }

        return $items;
    }

    private function routes(Employee $employee, ?int $managerId, array $items, array $pending, $mappings, $recipients): array
    {
        if ($items === []) {
            return [];
        }
        $routes = [];
        $coverable = array_values(array_filter($items, fn (array $item): bool => $item['type'] !== 'missing_punch'));
        // Leave approval cannot fix a missing punch, so an approver only gets a route for leave-coverable items.
        foreach ($coverable === [] ? [] : collect($pending)->sortBy('request_id') as $request) {
            $text = trim((string) ($request['approver_name'] ?? ''));
            $key = ApproverMappings::key($text);
            $mapping = $mappings->get($key);
            $approver = $mapping === null ? null : $recipients->get($mapping->employee_id);
            $available = $approver !== null && $approver->email !== null && trim($approver->email) !== ''
                && $approver->email !== $employee->email;
            $routes[] = [
                'key' => 'approver:'.$request['request_id'], 'kind' => 'approver',
                'request_id' => $request['request_id'], 'external_id' => $request['external_id'],
                'approver_text' => $text, 'approver_key' => $key,
                'recipient_employee_id' => $approver?->id, 'recipient_available' => $available,
                'items' => $coverable,
            ];
        }
        if ($pending === [] || $coverable === [] || in_array('missing_punch', array_column($items, 'type'), true)) {
            $managerItems = $pending === [] ? $items : array_values(array_filter($items,
                fn (array $item): bool => $item['type'] === 'missing_punch'));
            if ($managerItems !== []) {
                $manager = $managerId === null ? null : $recipients->get($managerId);
                $address = $manager?->email;
                if ($address === null || trim($address) === '' || $address === $employee->email) {
                    $address = $employee->fallback_notification_email;
                }
                $available = $address !== null && trim($address) !== '' && $address !== $employee->email;
                $routes[] = [
                    'key' => 'manager', 'kind' => 'manager', 'request_id' => null, 'external_id' => null,
                    'approver_text' => null, 'approver_key' => null,
                    'recipient_employee_id' => $manager?->id, 'recipient_available' => $available,
                    'items' => $managerItems,
                ];
            }
        }

        return $routes;
    }

    private function state(array $items, array $routes, ?FollowUpCase $old): string
    {
        if ($items === []) {
            return 'closed';
        }
        if (collect($routes)->contains(fn (array $route): bool => ! $route['recipient_available'])) {
            return 'waiting_hr';
        }
        if (collect($routes)->contains('kind', 'approver')) {
            return 'waiting_approver';
        }

        return $old?->state === 'waiting_manager' && $old->items == $items && $old->routes == $routes
            ? 'waiting_manager' : 'open';
    }

    private function closureSource(?array $result, $allocations): array
    {
        if ($result === null) {
            return ['kind' => 'reassessment', 'request_numbers' => [], 'credit_ids' => []];
        }
        $requestNumbers = [];
        $creditIds = [];
        foreach ($allocations as $allocation) {
            if ($allocation->source_credit_id !== null) {
                $creditIds[] = (int) $allocation->source_credit_id;
            }
            if (isset($allocation->explanation['request'])) {
                $requestNumbers[] = (string) $allocation->explanation['request'];
            }
        }
        $requestNumbers = array_values(array_unique($requestNumbers));
        $creditIds = array_values(array_unique($creditIds));
        sort($requestNumbers);
        sort($creditIds, SORT_NUMERIC);

        return [
            'kind' => $requestNumbers !== [] || $creditIds !== [] ? 'reconciliation' : 'reassessment',
            'request_numbers' => $requestNumbers,
            'credit_ids' => $creditIds,
        ];
    }

    private function same(FollowUpCase $case, array $values): bool
    {
        foreach ($values as $field => $value) {
            $current = $case->{$field};
            if (in_array($field, ['closed_at', 'manual_closed_at'], true)) {
                $current = $current?->toDateTimeString();
                $value = $value?->toDateTimeString();
            }
            if ($current != $value) {
                return false;
            }
        }

        return true;
    }

    private function snapshot(FollowUpCase $case): array
    {
        return [
            'day_id' => $case->day_id, 'state' => $case->state,
            'items' => $case->items, 'routes' => $case->routes, 'request_ids' => $case->request_ids,
            'closure_reason' => $case->closure_reason, 'closure_source' => $case->closure_source,
            'closed_at' => $case->closed_at?->toDateTimeString(),
            'manual_closed_items' => $case->manual_closed_items,
            'manual_closed_by' => $case->manual_closed_by,
            'manual_closed_at' => $case->manual_closed_at?->toDateTimeString(),
        ];
    }

    private function changeEvent(array $before, FollowUpCase $case): string
    {
        if ($case->state === 'closed' && $before['state'] !== 'closed') {
            return 'closed';
        }
        if ($case->state !== 'closed' && ($before['state'] === 'closed'
            || ($before['state'] === 'waiting_approver' && ! collect($case->routes)->contains('kind', 'approver')))) {
            return 'reopened';
        }

        return 'changed';
    }

    private function event(FollowUpCase $case, string $event, ?array $before, array $after, string $source, ?User $actor = null, ?string $reason = null): void
    {
        CaseEvent::create([
            'company_id' => $case->company_id, 'case_id' => $case->id,
            'event' => $event, 'before' => $before, 'after' => $after,
            'source' => $source, 'actor_id' => $actor?->id, 'created_at' => now(),
        ]);
        $metadata = [
            'before_state' => $before['state'] ?? null, 'after_state' => $after['state'],
            'item_types' => array_column($after['items'], 'type'), 'source' => $source,
        ];
        if ($reason !== null) {
            $metadata['reason'] = $reason;
        }
        $this->audit->log('attendance.case.'.$event, $case, $metadata, $actor);
    }

    private function refreshIssues(Company $company, Employee $employee, array $affectedApprovers, array $affectedManagers): void
    {
        $openCases = FollowUpCase::query()->where('employee_id', $employee->id)->where('state', '!=', 'closed')->get();
        $missingManager = false;
        $approverKeys = [];
        foreach ($openCases as $case) {
            foreach ($case->routes as $route) {
                // A known manager without an address is one issue for all of their employees.
                if ($route['kind'] === 'manager' && ! $route['recipient_available'] && $route['recipient_employee_id'] === null) {
                    $missingManager = true;
                }
                if ($route['kind'] === 'approver' && ! $route['recipient_available']) {
                    $key = $route['approver_key'];
                    if ($route['recipient_employee_id'] === null) {
                        $approverKeys[$key] = $route['approver_text'];
                    } else {
                        $this->issues->open($company, 'notification_recipient_missing', 'approver', 'approver:'.hash('sha256', $key));
                    }
                }
            }
        }
        if ($missingManager) {
            $this->issues->open($company, 'notification_recipient_missing', 'employee', 'employee:'.$employee->id);
        } else {
            $this->resolveIssue($company, 'notification_recipient_missing|employee|employee:'.$employee->id);
        }
        foreach ($affectedManagers as $managerId) {
            $needed = FollowUpCase::query()->where('state', '!=', 'closed')
                ->whereJsonContains('routes', ['kind' => 'manager', 'recipient_employee_id' => $managerId])->get(['routes'])
                ->contains(fn (FollowUpCase $case): bool => collect($case->routes)->contains(fn (array $route): bool => $route['kind'] === 'manager'
                    && $route['recipient_employee_id'] === $managerId && ! $route['recipient_available']));
            if ($needed) {
                $this->issues->open($company, 'notification_recipient_missing', 'manager', 'manager:'.$managerId);
            } else {
                $this->resolveIssue($company, 'notification_recipient_missing|manager|manager:'.$managerId);
            }
        }
        foreach ($approverKeys as $key => $text) {
            $this->issues->open($company, 'approver_unmapped', 'approver', 'approver:'.hash('sha256', $key),
                details: ['approver_text' => $text]);
        }
        foreach ($affectedApprovers as $key) {
            $needed = [];
            $related = FollowUpCase::query()->where('state', '!=', 'closed')
                ->whereJsonContains('routes', ['approver_key' => $key])->get(['routes']);
            foreach ($related as $case) {
                foreach ($case->routes as $route) {
                    if ($route['kind'] === 'approver' && $route['approver_key'] === $key && ! $route['recipient_available']) {
                        $type = $route['recipient_employee_id'] === null ? 'approver_unmapped' : 'notification_recipient_missing';
                        $needed[$type] = true;
                    }
                }
            }
            foreach (['approver_unmapped', 'notification_recipient_missing'] as $type) {
                if (! isset($needed[$type])) {
                    $this->resolveIssue($company, $type.'|approver|approver:'.hash('sha256', $key));
                }
            }
        }
    }

    private function resolveIssue(Company $company, string $openKey): void
    {
        $issue = DataIssue::query()->where('company_id', $company->id)->where('open_key', $openKey)->first();
        if ($issue === null) {
            return;
        }
        $issue->update(['status' => 'resolved', 'open_key' => null,
            'note' => __('messages.follow_up.issue_resolved'), 'resolved_at' => now()]);
        $this->audit->log('attendance.issue.resolved', $issue,
            ['type' => $issue->type, 'subject_key' => $issue->subject_key]);
    }
}
