<?php

namespace App\Services\Leave;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDecision;
use App\Models\User;
use App\Services\Attendance\HolidayCalendar;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LeaveActions
{
    public function __construct(private AuditLogger $audit, private ReconciliationTriggers $triggers) {}

    public function cancel(LeaveRequest $request, string $reason, User $actor): RequestDecision
    {
        return $this->record($request, 'cancel', [], $reason, $actor);
    }

    public function confirmDistribution(LeaveRequest $request, string $mode, ?array $dates, string $reason, User $actor): RequestDecision
    {
        $this->authorize($request->company_id, $actor);
        if (! in_array($mode, ['from_start', 'dates'], true) || ($mode === 'dates' && ($dates === null || $dates === []))) {
            throw ValidationException::withMessages(['distribution' => __('messages.leave_reconciliation.invalid_distribution')]);
        }
        if ($mode === 'dates' && array_filter($dates, fn ($date): bool => ! is_string($date)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) !== []) {
            throw ValidationException::withMessages(['distribution' => __('messages.leave_reconciliation.invalid_distribution')]);
        }
        if ($mode === 'dates' && $request->employee !== null) {
            $company = Company::findOrFail($request->company_id);
            $holidayDates = app(HolidayCalendar::class)->forEmployeeDates($company, collect([$request->employee]),
                min($dates), max($dates))[$request->employee_id] ?? [];
            if (array_intersect($dates, array_keys($holidayDates)) !== []) {
                throw ValidationException::withMessages(['distribution' => __('messages.leave_reconciliation.holiday_date')]);
            }
        }

        return $this->record($request, 'confirm_distribution', ['mode' => $mode, 'dates' => $dates], $reason, $actor);
    }

    public function fixDates(LeaveRequest $request, string $start, string $end, float $duration, string $reason, User $actor): RequestDecision
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)
            || ! checkdate((int) substr($start, 5, 2), (int) substr($start, 8, 2), (int) substr($start, 0, 4))
            || ! checkdate((int) substr($end, 5, 2), (int) substr($end, 8, 2), (int) substr($end, 0, 4))
            || $start < '2000-01-01' || $end > today('Asia/Baghdad')->addYear()->toDateString()
            || $end < $start || $duration <= 0 || $duration * 2 !== (float) (int) ($duration * 2)) {
            throw ValidationException::withMessages(['dates' => __('messages.leave_reconciliation.invalid_dates')]);
        }

        return $this->record($request, 'fix_dates', [
            'start_date' => $start, 'end_date' => $end, 'duration_days' => $duration,
        ], $reason, $actor);
    }

    public function reverseDeduction(Employee $employee, string $date, string $kind, string $reason, User $actor): RequestDecision
    {
        $this->authorize($employee->company_id, $actor);
        $this->reason($reason);
        if (! in_array($kind, ['absence', 'late', 'early', 'exit'], true)) {
            throw ValidationException::withMessages(['target_kind' => __('messages.leave_reconciliation.invalid_target')]);
        }
        $allocation = Allocation::query()->where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)->where('target_kind', $kind)
            ->whereNotNull('source_credit_id')->whereNull('superseded_by_run_id')
            ->whereHas('targetDay', fn ($query) => $query->whereDate('work_date', $date))->first();
        if ($allocation === null) {
            throw ValidationException::withMessages(['deduction' => __('messages.leave_reconciliation.no_deduction')]);
        }
        $request = $allocation->sourceCredit->sourceDay->request;

        return $this->record($request, 'reverse_deduction', [], $reason, $actor, $employee, $date, $kind);
    }

    public function withdraw(RequestDecision $decision, string $reason, User $actor): RequestDecision
    {
        $request = LeaveRequest::query()->whereKey($decision->request_id)->firstOrFail();
        $this->authorize($request->company_id, $actor);
        $this->reason($reason);
        if ($decision->decision === 'withdraw' || RequestDecision::query()->where('request_id', $request->id)
            ->where('decision', 'withdraw')->where('values->decision_id', $decision->id)->exists()) {
            throw ValidationException::withMessages(['decision' => __('messages.leave_reconciliation.already_withdrawn')]);
        }

        return $this->record($request, 'withdraw', ['decision_id' => $decision->id], $reason, $actor,
            $decision->employee_id === null ? null : Employee::findOrFail($decision->employee_id),
            $decision->work_date?->toDateString(), $decision->target_kind);
    }

    private function record(LeaveRequest $request, string $kind, array $values, string $reason, User $actor, ?Employee $employee = null, ?string $date = null, ?string $targetKind = null): RequestDecision
    {
        $this->authorize($request->company_id, $actor);
        $this->reason($reason);
        $employee ??= $request->employee;

        return DB::transaction(function () use ($request, $kind, $values, $reason, $actor, $employee, $date, $targetKind): RequestDecision {
            $decision = RequestDecision::create([
                'company_id' => $request->company_id, 'request_id' => $request->id,
                'employee_id' => $employee?->id, 'work_date' => $date, 'target_kind' => $targetKind,
                'decision' => $kind, 'values' => $values, 'reason' => $reason, 'created_by' => $actor->id,
            ]);
            $this->audit->log('leave.decision.'.$kind, $decision, [
                'before' => ['request_id' => $request->id], 'after' => $values + ['decision' => $kind], 'reason' => $reason,
            ], $actor);
            if ($employee !== null) {
                $this->triggers->dispatch($request->company_id, [$employee->id], 'decision');
            }

            return $decision;
        });
    }

    private function authorize(int $companyId, User $actor): void
    {
        $company = Company::findOrFail($companyId);
        abort_unless(auth()->id() === $actor->id && app(TenantContext::class)->companyId() === $companyId, 403);
        Gate::forUser($actor)->authorize('manageLeave', $company);
    }

    private function reason(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('messages.leave_reconciliation.reason_required')]);
        }
    }
}
