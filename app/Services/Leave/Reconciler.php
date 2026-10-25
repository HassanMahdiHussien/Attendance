<?php

namespace App\Services\Leave;

use App\Jobs\Attendance\AssessDaysJob;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\Policy;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\EntitlementConsumption;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\ProcessingRun;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestDecision;
use App\Models\Leave\Setting;
use App\Services\Attendance\DataIssues;
use App\Services\Attendance\FollowUp\CaseSync;
use App\Services\Attendance\HolidayCalendar;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class Reconciler
{
    public function __construct(
        private LeaveDistributor $distributor,
        private LeaveAllocator $allocator,
        private LeaveEntitlementAllocator $entitlementAllocator,
        private AuditLogger $audit,
        private DataIssues $issues,
        private CaseSync $cases,
    ) {}

    /** Open request-level issue keys of the employee being replayed, loaded once per replay. */
    private array $openRequestIssues = [];

    public function run(Company $company, array $employeeIds, string $trigger, ?string $from = null, ?string $to = null): ProcessingRun
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        sort($ids, SORT_NUMERIC);
        $run = ProcessingRun::create([
            'company_id' => $company->id, 'trigger_source' => $trigger,
            'scope' => ['employee_ids' => $ids, 'from' => $from, 'to' => $to], 'status' => 'processing', 'started_at' => now(),
        ]);
        try {
            $changed = 0;
            $assessmentDates = [];
            foreach (array_chunk($ids, 100) as $chunk) {
                $changed += DB::transaction(function () use ($company, $chunk, $run, &$assessmentDates): int {
                    $employees = Employee::query()->where('company_id', $company->id)->whereIn('id', $chunk)
                        ->orderBy('id')->lockForUpdate()->get();
                    $count = 0;
                    foreach ($employees as $employee) {
                        $result = $this->replayEmployee($company, $employee, $run);
                        $this->cases->syncLocked($company, $employee, $run->trigger_source);
                        $count += (int) $result['changed'];
                        if ($result['dates'] !== []) {
                            $assessmentDates[$employee->id] = $result['dates'];
                        }
                    }

                    return $count;
                });
            }
            $run->update(['status' => 'completed', 'counts' => ['employees_changed' => $changed], 'finished_at' => now()]);
            $this->audit->log('leave.reconciled', $run, ['employee_ids' => $ids, 'employees_changed' => $changed]);
            $this->dispatchChangedDays($company->id, $assessmentDates);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'finished_at' => now()]);

            throw $exception;
        }

        return $run->fresh();
    }

    private function replayEmployee(Company $company, Employee $employee, ProcessingRun $run): array
    {
        $requests = LeaveRequest::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->with('leaveType')->orderBy('id')->get();
        $this->openRequestIssues = DataIssue::query()->where('company_id', $company->id)
            ->whereIn('open_key', $requests->flatMap(fn (LeaveRequest $request): array => array_map(
                fn (string $type): string => $type.'|leave_request|request:'.$request->id,
                ['leave_counted_holiday', 'leave_duration_mismatch', 'leave_rest_day_missing', 'leave_entitlement_exceeded'],
            ))->all())->pluck('open_key')->flip()->all();
        $versions = EmployeeVersion::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->orderBy('effective_from')->orderBy('id')->get()
            ->map(fn (EmployeeVersion $version): array => [
                'id' => $version->id, 'effective_from' => $version->effective_from->toDateString(), 'changes' => $version->changes,
            ])->all();
        $policies = Policy::query()->where('company_id', $company->id)->orderBy('effective_from')->orderBy('version')->get()
            ->map(fn (Policy $policy): array => [
                'effective_from' => $policy->effective_from->toDateString(), 'version' => $policy->version,
                'office_rest_days' => $policy->office_rest_days, 'shift_rest_days' => $policy->shift_rest_days,
            ])->all();
        $decisions = RequestDecision::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->orWhere(function ($query) use ($requests, $company): void {
                $query->where('company_id', $company->id)->whereIn('request_id', $requests->pluck('id'));
            })->orderBy('id')->get();
        $withdrawn = [];
        foreach ($decisions as $decision) {
            if ($decision->decision === 'withdraw') {
                $withdrawn[(int) ($decision->values['decision_id'] ?? 0)] = true;
            }
        }
        $active = $decisions->reject(fn (RequestDecision $decision): bool => $decision->decision === 'withdraw' || isset($withdrawn[$decision->id]));
        $byRequest = $active->groupBy('request_id');
        $boundaries = [];
        foreach ($requests as $request) {
            if ($request->start_date !== null && $request->end_date !== null
                && $request->start_date->toDateString() >= '2000-01-01') {
                $boundaries[] = $request->start_date->toDateString();
                $boundaries[] = $request->end_date->toDateString();
            }
        }
        foreach ($active->where('decision', 'fix_dates') as $decision) {
            $boundaries[] = $decision->values['start_date'] ?? null;
            $boundaries[] = $decision->values['end_date'] ?? null;
        }
        $boundaries = array_values(array_filter($boundaries));
        $holidayDates = $boundaries === [] ? [] : array_keys(app(HolidayCalendar::class)
            ->forEmployeeDates($company, collect([$employee]), min($boundaries), max($boundaries))[$employee->id] ?? []);
        $blocked = [];
        foreach ($active as $decision) {
            if ($decision->decision === 'reverse_deduction') {
                $blocked[$decision->work_date->toDateString().'|'.$decision->target_kind] = true;
            }
        }
        $plannedDays = [];
        $compensationRequests = [];
        $compensationIds = [];
        $setting = Setting::query()->where('company_id', $company->id)->first();
        $leaveCalendarRestDays = $setting?->leave_calendar_rest_days ?? [5];
        $methodsChanged = false;
        foreach ($requests as $request) {
            if ($request->leaveType?->consumes === 'rest_day_entitlement') {
                $compensationIds[$request->id] = true;
            }
            $requestDecisions = $byRequest->get($request->id, collect());
            if ($request->status !== 'approved' || ! $request->leaveType?->covers || ! $request->leaveType->is_mapped
                || $requestDecisions->contains('decision', 'cancel')) {
                $methodsChanged = $this->syncCountedHoliday($company, $request, false) || $methodsChanged;
                if ($request->counting_method !== null) {
                    $this->audit->log('leave.distribution.method_changed', $request, [
                        'before' => $request->counting_method, 'after' => null,
                    ]);
                    $request->update(['counting_method' => null]);
                    $methodsChanged = true;
                }

                continue;
            }
            $values = [
                'start_date' => $request->start_date?->toDateString(), 'end_date' => $request->end_date?->toDateString(),
                'duration_days' => $request->duration_days,
            ];
            foreach ($requestDecisions as $decision) {
                if ($decision->decision === 'fix_dates') {
                    $values = array_replace($values, $decision->values ?? []);
                }
            }
            if (in_array('invalid_dates', $request->flags ?? [], true)
                && ! $requestDecisions->contains('decision', 'fix_dates')) {
                $methodsChanged = $this->syncCountedHoliday($company, $request, false) || $methodsChanged;
                if ($request->counting_method !== null) {
                    $this->audit->log('leave.distribution.method_changed', $request, [
                        'before' => $request->counting_method, 'after' => null,
                    ]);
                    $request->update(['counting_method' => null]);
                    $methodsChanged = true;
                }

                continue;
            }
            $confirmation = $requestDecisions->last(fn (RequestDecision $decision): bool => $decision->decision === 'confirm_distribution')?->values;
            $distribution = $this->distributor->distribute($values, $versions, $policies, $confirmation,
                $leaveCalendarRestDays, $holidayDates);
            $methodsChanged = $this->syncCountedHoliday($company, $request,
                $distribution['flag'] === 'counted_holiday') || $methodsChanged;
            if ($request->counting_method !== $distribution['counting_method']) {
                $this->audit->log('leave.distribution.method_changed', $request, [
                    'before' => $request->counting_method, 'after' => $distribution['counting_method'],
                ]);
                $request->update(['counting_method' => $distribution['counting_method']]);
                $methodsChanged = true;
            }
            $mismatchKey = 'leave_duration_mismatch|leave_request|request:'.$request->id;
            if ($distribution['flag'] === 'duration_mismatch') {
                $this->issues->open($company, 'leave_duration_mismatch', 'leave_request', 'request:'.$request->id);
            } else {
                $mismatch = isset($this->openRequestIssues[$mismatchKey])
                    ? DataIssue::query()->where('company_id', $company->id)->where('open_key', $mismatchKey)->first() : null;
                if ($mismatch !== null) {
                    $mismatch->update(['status' => 'resolved', 'open_key' => null,
                        'note' => __('messages.leave_reconciliation.distribution_resolved'), 'resolved_at' => now()]);
                    $this->audit->log('leave.issue.resolved', $mismatch, ['type' => $mismatch->type, 'subject_key' => $mismatch->subject_key]);
                }
            }
            $restKey = 'leave_rest_day_missing|leave_request|request:'.$request->id;
            if ($distribution['flag'] === 'rest_day_missing') {
                $this->issues->open($company, 'leave_rest_day_missing', 'leave_request', 'request:'.$request->id);
            } else {
                $restIssue = isset($this->openRequestIssues[$restKey])
                    ? DataIssue::query()->where('company_id', $company->id)->where('open_key', $restKey)->first() : null;
                if ($restIssue !== null) {
                    $restIssue->update(['status' => 'resolved', 'open_key' => null,
                        'note' => __('messages.leave_reconciliation.distribution_resolved'), 'resolved_at' => now()]);
                    $this->audit->log('leave.issue.resolved', $restIssue, ['type' => $restIssue->type, 'subject_key' => $restIssue->subject_key]);
                }
            }
            if ($distribution['flag'] !== null && $distribution['flag'] !== 'counted_holiday') {
                continue;
            }
            if (isset($compensationIds[$request->id])) {
                $compensationRequests[] = [
                    'request_id' => $request->id, 'external_id' => $request->external_id,
                    'start_date' => $values['start_date'], 'quantity' => (float) $values['duration_days'],
                    'request_day_key' => isset($distribution['days'][0])
                        ? $request->id.'|'.$distribution['days'][0]['work_date'] : null,
                ];
            }
            foreach ($distribution['days'] as $day) {
                $plannedDays[] = [
                    'key' => $request->id.'|'.$day['work_date'], 'request_id' => $request->id,
                    'external_id' => $request->external_id, 'work_date' => $day['work_date'], 'minutes' => $day['minutes'],
                    'credit_allowed' => ! isset($compensationIds[$request->id]),
                ];
            }
        }
        $days = Day::query()->with('policy')->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->orderBy('work_date')->get();
        $targets = [];
        foreach ($days as $day) {
            $date = $day->work_date->toDateString();
            if ($day->status === 'absent') {
                $targets[] = ['day_id' => $day->id, 'work_date' => $date, 'kind' => 'absence',
                    'minutes' => (int) ($day->inputs['absence_minutes'] ?? $day->policy?->absence_minutes ?? 480)];
            } elseif ($day->status === 'present') {
                foreach (['late' => 'late_minutes', 'early' => 'early_minutes', 'exit' => 'exit_minutes'] as $kind => $column) {
                    if ($kind === 'exit' && ! $day->policy?->exits_count_toward_balance) {
                        continue;
                    }
                    if ((int) $day->{$column} > 0) {
                        $targets[] = ['day_id' => $day->id, 'work_date' => $date, 'kind' => $kind, 'minutes' => (int) $day->{$column}];
                    }
                }
            }
        }
        $oldDays = RequestDay::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->get();
        $oldByKey = $oldDays->keyBy(fn (RequestDay $day): string => $day->request_id.'|'.$day->work_date->toDateString());
        $sameDays = count($plannedDays) === $oldDays->count();
        foreach ($plannedDays as $day) {
            $sameDays = $sameDays && (int) ($oldByKey->get($day['key'])?->minutes ?? -1) === $day['minutes'];
        }
        $newByKey = collect($plannedDays)->keyBy('key');
        $changedDates = [];
        foreach ($oldByKey as $key => $oldDay) {
            if ((int) ($newByKey->get($key)['minutes'] ?? -1) !== (int) $oldDay->minutes) {
                $changedDates[] = $oldDay->work_date->toDateString();
            }
        }
        foreach ($plannedDays as $day) {
            if ((int) ($oldByKey->get($day['key'])?->minutes ?? -1) !== $day['minutes']) {
                $changedDates[] = $day['work_date'];
            }
        }
        $changedDates = array_values(array_unique($changedDates));
        $oldCredits = Credit::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->get();
        $oldAllocations = Allocation::query()->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->get();
        $oldConsumptions = EntitlementConsumption::query()->with('requestDay')
            ->where('company_id', $company->id)->where('employee_id', $employee->id)
            ->whereNull('superseded_by_run_id')->get();
        $availableEntitlements = RestDayEntitlement::query()->where('company_id', $company->id)
            ->where('employee_id', $employee->id)->whereNull('superseded_by_run_id')
            ->orderBy('work_date')->orderBy('id')->get()
            ->map(fn (RestDayEntitlement $entitlement): array => [
                'id' => $entitlement->id, 'work_date' => $entitlement->work_date->toDateString(),
                'quantity' => $entitlement->quantity,
            ])->all();
        $entitlementPlan = $this->entitlementAllocator->allocate($compensationRequests, $availableEntitlements);
        $exceeded = array_fill_keys($entitlementPlan['exceeded'], true);
        foreach ($requests as $request) {
            $methodsChanged = $this->syncEntitlementExceeded($company, $request, isset($exceeded[$request->id])) || $methodsChanged;
        }
        $expiry = $setting?->credit_expiry_days;
        $sourceDays = [];
        foreach ($plannedDays as $day) {
            $sourceDays[] = ['id' => $day['key'], 'external_id' => $day['external_id'],
                'work_date' => $day['work_date'], 'minutes' => $day['minutes'],
                'credit_allowed' => $day['credit_allowed']];
        }
        $targetlessPresentDates = $days->filter(fn (Day $day): bool => $day->status === 'present'
            && ! collect($targets)->contains('work_date', $day->work_date->toDateString()))
            ->map(fn (Day $day): string => $day->work_date->toDateString())->all();
        $creditEligibleDates = $days->filter(fn (Day $day): bool => in_array($day->status, ['present', 'absent'], true))
            ->map(fn (Day $day): string => $day->work_date->toDateString())->all();
        $plan = $this->allocator->allocate($sourceDays, $targets, $blocked, $expiry === null ? null : (int) $expiry,
            $targetlessPresentDates, $creditEligibleDates);
        $flagKeys = [];
        foreach ($plan['flags'] as $flag) {
            $key = 'employee:'.$employee->id.':request_day:'.$flag['request_day_id'];
            $flagKeys[] = 'leave_with_attendance_nothing_to_reconcile|leave_request_day|'.$key;
            $this->issues->open($company, $flag['code'], 'leave_request_day', $key);
        }
        DataIssue::query()->where('company_id', $company->id)->where('type', 'leave_with_attendance_nothing_to_reconcile')
            ->where('status', 'open')->where('subject_key', 'like', 'employee:'.$employee->id.':request_day:%')
            ->whereNotIn('open_key', $flagKeys)->get()->each(function (DataIssue $issue): void {
                $issue->update(['status' => 'resolved', 'open_key' => null,
                    'note' => __('messages.leave_reconciliation.issue_resolved'), 'resolved_at' => now()]);
                $this->audit->log('leave.issue.resolved', $issue, ['type' => $issue->type, 'subject_key' => $issue->subject_key]);
            });
        $sameConsumptions = $this->sameConsumptionLedger($entitlementPlan['consumptions'], $oldConsumptions);
        if (! $methodsChanged && $sameDays && $this->sameLedger($plan, $oldCredits, $oldAllocations, $oldDays)
            && $sameConsumptions) {
            return ['changed' => false, 'dates' => []];
        }
        $oldAllocations->each(fn (Allocation $allocation) => $allocation->update(['superseded_by_run_id' => $run->id]));
        $oldCredits->each(fn (Credit $credit) => $credit->update(['superseded_by_run_id' => $run->id]));
        if (! $sameDays) {
            $oldDays->each(fn (RequestDay $day) => $day->update(['superseded_by_run_id' => $run->id]));
        }
        $ids = [];
        foreach ($plannedDays as $day) {
            $ids[$day['key']] = $sameDays ? $oldByKey[$day['key']]->id : RequestDay::create([
                'company_id' => $company->id, 'employee_id' => $employee->id, 'request_id' => $day['request_id'],
                'work_date' => $day['work_date'], 'minutes' => $day['minutes'], 'run_id' => $run->id,
            ])->id;
        }
        $creditIds = [];
        foreach ($plan['credits'] as $credit) {
            $creditIds[$credit['source_day_id']] = Credit::create([
                'company_id' => $company->id, 'employee_id' => $employee->id,
                'source_day_id' => $ids[$credit['source_day_id']], 'run_id' => $run->id,
                'minutes' => $credit['minutes'], 'expires_on' => $credit['expires_on'],
            ])->id;
        }
        foreach ($plan['allocations'] as $allocation) {
            $created = Allocation::create([
                'company_id' => $company->id, 'employee_id' => $employee->id,
                'source_request_day_id' => $allocation['source_kind'] === 'same_day' ? $ids[$allocation['source_day_id']] : null,
                'source_credit_id' => $allocation['source_kind'] === 'credit' ? $creditIds[$allocation['source_day_id']] : null,
                'target_day_id' => $allocation['target_day_id'], 'target_kind' => $allocation['target_kind'],
                'minutes' => $allocation['minutes'], 'explanation' => $allocation['explanation'], 'run_id' => $run->id,
            ]);
            $this->audit->log('leave.allocation.created', $created, [
                'before' => [], 'after' => [
                    'source_kind' => $allocation['source_kind'], 'source_day_id' => $allocation['source_day_id'],
                    'target_day_id' => $allocation['target_day_id'], 'target_kind' => $allocation['target_kind'],
                    'minutes' => $allocation['minutes'], 'run_id' => $run->id,
                ],
            ]);
        }
        if (! $sameConsumptions || (! $sameDays && $oldConsumptions->isNotEmpty())) {
            foreach ($oldConsumptions as $consumption) {
                $consumption->update(['superseded_by_run_id' => $run->id]);
                $this->audit->log('leave.entitlement.consumption_superseded', $consumption, [
                    'request_id' => $consumption->request_id, 'entitlement_id' => $consumption->entitlement_id,
                    'before' => ['run_id' => $consumption->run_id], 'after' => ['superseded_by_run_id' => $run->id],
                ]);
            }
            foreach ($entitlementPlan['consumptions'] as $consumption) {
                $created = EntitlementConsumption::create([
                    'company_id' => $company->id, 'employee_id' => $employee->id,
                    'request_id' => $consumption['request_id'],
                    'request_day_id' => $consumption['request_day_key'] === null ? null : $ids[$consumption['request_day_key']],
                    'entitlement_id' => $consumption['entitlement_id'],
                    'quantity' => $consumption['quantity'], 'explanation' => $consumption['explanation'],
                    'run_id' => $run->id,
                ]);
                $this->audit->log('leave.entitlement.consumed', $created, [
                    'request_id' => $created->request_id, 'entitlement_id' => $created->entitlement_id,
                    'quantity' => $created->quantity, 'run_id' => $run->id,
                ]);
            }
        }

        return ['changed' => true, 'dates' => $changedDates];
    }

    private function syncCountedHoliday(Company $company, LeaveRequest $request, bool $counted): bool
    {
        $flags = $request->flags ?? [];
        $has = in_array('counted_holiday', $flags, true);
        if ($has !== $counted) {
            $flags = array_values(array_diff($flags, ['counted_holiday']));
            if ($counted) {
                $flags[] = 'counted_holiday';
            }
            $request->update(['flags' => $flags]);
            $this->audit->log('leave.request.holiday_flag_changed', $request, [
                'request_id' => $request->id, 'counted_holiday' => $counted,
            ]);
        }
        $key = 'leave_counted_holiday|leave_request|request:'.$request->id;
        if ($counted) {
            $this->issues->open($company, 'leave_counted_holiday', 'leave_request', 'request:'.$request->id);
        } else {
            $issue = isset($this->openRequestIssues[$key])
                ? DataIssue::query()->where('company_id', $company->id)->where('open_key', $key)->first() : null;
            if ($issue !== null) {
                $issue->update(['status' => 'resolved', 'open_key' => null,
                    'note' => __('messages.leave_reconciliation.distribution_resolved'), 'resolved_at' => now()]);
                $this->audit->log('leave.issue.resolved', $issue, ['type' => $issue->type, 'subject_key' => $issue->subject_key]);
            }
        }

        return $has !== $counted;
    }

    private function syncEntitlementExceeded(Company $company, LeaveRequest $request, bool $exceeded): bool
    {
        $flags = $request->flags ?? [];
        $hadFlag = in_array('entitlement_exceeded', $flags, true);
        if ($hadFlag !== $exceeded) {
            $flags = array_values(array_diff($flags, ['entitlement_exceeded']));
            if ($exceeded) {
                $flags[] = 'entitlement_exceeded';
            }
            $request->update(['flags' => $flags]);
            $this->audit->log('leave.request.entitlement_flag_changed', $request, [
                'request_id' => $request->id, 'exceeded' => $exceeded,
            ]);
        }
        $key = 'leave_entitlement_exceeded|leave_request|request:'.$request->id;
        if ($exceeded) {
            $this->issues->open($company, 'leave_entitlement_exceeded', 'leave_request', 'request:'.$request->id);
        } else {
            $issue = isset($this->openRequestIssues[$key])
                ? DataIssue::query()->where('company_id', $company->id)->where('open_key', $key)->first() : null;
            if ($issue !== null) {
                $issue->update(['status' => 'resolved', 'open_key' => null,
                    'note' => __('messages.leave_reconciliation.entitlement_resolved'), 'resolved_at' => now()]);
                $this->audit->log('leave.issue.resolved', $issue, ['type' => $issue->type, 'subject_key' => $issue->subject_key]);
            }
        }

        return $hadFlag !== $exceeded;
    }

    private function sameConsumptionLedger(array $planned, $current): bool
    {
        $plannedRows = array_map(fn (array $row): array => [
            $row['request_id'], $row['request_day_key'] ?? '', $row['entitlement_id'],
            (int) round($row['quantity'] * 2),
        ], $planned);
        $currentRows = $current->map(fn (EntitlementConsumption $row): array => [
            (int) $row->request_id,
            $row->requestDay === null ? '' : $row->requestDay->request_id.'|'.$row->requestDay->work_date->toDateString(),
            (int) $row->entitlement_id, (int) round((float) $row->quantity * 2),
        ])->all();
        sort($plannedRows);
        sort($currentRows);

        return $plannedRows === $currentRows;
    }

    private function dispatchChangedDays(int $companyId, array $assessmentDates): void
    {
        if ($assessmentDates === []) {
            return;
        }
        // Only days that already exist are reassessed: a date without a day has no attendance coverage yet,
        // and the coverage-driven assessment reads the leave when that coverage arrives.
        $existing = [];
        DB::table('attendance_days')->where('company_id', $companyId)->whereNull('superseded_by_run_id')
            ->whereIn('employee_id', array_keys($assessmentDates))
            ->whereIn('work_date', array_values(array_unique(array_merge(...array_values($assessmentDates)))))
            ->select(['employee_id', 'work_date'])->orderBy('id')
            ->each(function (object $day) use (&$existing): void {
                $existing[(int) $day->employee_id][substr((string) $day->work_date, 0, 10)] = true;
            });
        $groups = [];
        foreach ($assessmentDates as $employeeId => $dates) {
            $dates = array_values(array_filter($dates, fn (string $date): bool => isset($existing[(int) $employeeId][$date])));
            sort($dates);
            $from = null;
            $to = null;
            foreach ($dates as $date) {
                if ($from !== null && CarbonImmutable::parse($to)->addDay()->toDateString() < $date) {
                    $groups[$from.'|'.$to][] = (int) $employeeId;
                    $from = null;
                }
                $from ??= $date;
                $to = $date;
            }
            if ($from !== null) {
                $groups[$from.'|'.$to][] = (int) $employeeId;
            }
        }
        // Employees with the same changed range share one assessment job instead of one job each.
        foreach ($groups as $range => $employeeIds) {
            [$from, $to] = explode('|', $range);
            foreach (array_chunk($employeeIds, 100) as $chunk) {
                $this->queueAssessment($companyId, $chunk, $from, $to);
            }
        }
    }

    private function queueAssessment(int $companyId, array $employeeIds, string $from, string $to): void
    {
        $today = today('Asia/Baghdad')->toDateString();
        $scopeFrom = CarbonImmutable::parse($from)->subDay()->toDateString();
        if ($scopeFrom > $today) {
            return;
        }
        AssessDaysJob::dispatch($companyId, [
            'employee_ids' => $employeeIds,
            'from' => $scopeFrom,
            'to' => min(CarbonImmutable::parse($to)->addDay()->toDateString(), $today),
        ], 'leave_distribution')->afterCommit();
    }

    private function sameLedger(array $plan, $credits, $allocations, $days): bool
    {
        $dayKeys = $days->mapWithKeys(fn (RequestDay $day): array => [$day->id => $day->request_id.'|'.$day->work_date->toDateString()]);
        $creditKeys = $credits->mapWithKeys(fn (Credit $credit): array => [$credit->id => $dayKeys[$credit->source_day_id] ?? '']);
        $plannedCredits = array_map(fn (array $credit): array => [$credit['source_day_id'], $credit['minutes'], $credit['expires_on']], $plan['credits']);
        $existingCredits = $credits->map(fn (Credit $credit): array => [$dayKeys[$credit->source_day_id] ?? '', (int) $credit->minutes, $credit->expires_on?->toDateString()])->all();
        $planned = array_map(fn (array $allocation): array => [
            $allocation['source_kind'], $allocation['source_day_id'], $allocation['target_day_id'],
            $allocation['target_kind'], $allocation['minutes'],
        ], $plan['allocations']);
        $existing = $allocations->map(fn (Allocation $allocation): array => [
            $allocation->source_credit_id === null ? 'same_day' : 'credit',
            $allocation->source_credit_id === null ? ($dayKeys[$allocation->source_request_day_id] ?? '') : ($creditKeys[$allocation->source_credit_id] ?? ''),
            (int) $allocation->target_day_id, $allocation->target_kind, (int) $allocation->minutes,
        ])->all();
        sort($plannedCredits);
        sort($existingCredits);
        sort($planned);
        sort($existing);

        return $plannedCredits === $existingCredits && $planned === $existing;
    }
}
