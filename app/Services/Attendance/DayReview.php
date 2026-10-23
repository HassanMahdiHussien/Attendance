<?php

namespace App\Services\Attendance;

use App\Enums\UserRole;
use App\Jobs\Attendance\AssessDaysJob;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\ManualPunch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DayReview
{
    public function __construct(private AuditLogger $audit) {}

    public function addMissingTime(Employee $employee, string $date, string $time, string $role, string $reason, User $actor): ManualPunch
    {
        $this->authorize($employee, $actor);
        $this->requireReason($reason);
        if (! in_array($role, ['check_in', 'check_out'], true) || ! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            throw ValidationException::withMessages(['time' => __('messages.office_attendance.invalid_time')]);
        }
        $day = $this->reviewable($employee, $date);

        return DB::transaction(function () use ($employee, $date, $time, $role, $reason, $actor, $day): ManualPunch {
            $punchDate = $role === 'check_out' && $day->shift_start === '16:00:00' && $time <= '02:00'
                ? CarbonImmutable::parse($date)->addDay()->toDateString() : $date;
            if ($role === 'check_in' && $day->shift_start === '00:00:00' && $time >= '22:00') {
                $punchDate = CarbonImmutable::parse($date)->subDay()->toDateString();
            }
            $punch = ManualPunch::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'punched_at' => $punchDate.' '.$time.':00', 'role' => $role,
                'reason' => $reason, 'created_by' => $actor->id,
            ]);
            $this->audit->log('attendance.manual_punch.added', $punch, [
                'before' => [], 'after' => ['employee_id' => $employee->id, 'work_date' => $date, 'time' => $time, 'role' => $role],
                'reason' => $reason,
            ], $actor);
            $this->dispatch($employee, $date);

            return $punch;
        });
    }

    public function confirmAbsence(Employee $employee, string $date, string $reason, User $actor): DayDecision
    {
        $this->authorize($employee, $actor);
        $this->requireReason($reason);
        $day = $this->reviewable($employee, $date);

        return DB::transaction(function () use ($employee, $date, $reason, $actor, $day): DayDecision {
            $decision = DayDecision::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'work_date' => $date, 'decision' => 'confirm_absence', 'reason' => $reason,
                'before' => ['status' => $day->status], 'after' => ['status' => 'absent', 'reviewed' => true],
                'created_by' => $actor->id,
            ]);
            $this->audit->log('attendance.day.confirmed_absence', $decision, [
                'before' => $decision->before, 'after' => $decision->after, 'reason' => $reason,
            ], $actor);
            $this->dispatch($employee, $date);

            return $decision;
        });
    }

    public function pickShift(Employee $employee, string $date, string $shiftStart, string $reason, User $actor): DayDecision
    {
        $this->authorize($employee, $actor);
        $this->requireReason($reason);
        $day = Day::query()->where('company_id', $employee->company_id)->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)->whereNull('superseded_by_run_id')->firstOrFail();
        $starts = $day->policy?->shift_starts ?? [];
        if (! in_array($day->inputs['work_pattern'] ?? null, ['shift', 'security'], true)
            || ! in_array(substr($shiftStart, 0, 5), $starts, true)
            || ! in_array($day->status, ['shift_unresolved', 'missing_punch'], true)) {
            throw ValidationException::withMessages(['shift_start' => __('messages.office_attendance.invalid_shift')]);
        }

        return DB::transaction(function () use ($employee, $date, $shiftStart, $reason, $actor, $day): DayDecision {
            $choice = DayDecision::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'work_date' => $date, 'decision' => 'pick_shift', 'shift_start' => substr($shiftStart, 0, 5),
                'reason' => $reason, 'before' => ['shift_start' => $day->shift_start],
                'after' => ['shift_start' => substr($shiftStart, 0, 5)], 'created_by' => $actor->id,
            ]);
            $this->audit->log('attendance.shift.picked', $choice, [
                'before' => $choice->before, 'after' => $choice->after, 'reason' => $reason,
            ], $actor);
            $this->dispatch($employee, $date);

            return $choice;
        });
    }

    public function withdraw(DayDecision|ManualPunch $record, string $reason, User $actor): DayDecision|ManualPunch
    {
        $employee = Employee::query()->whereKey($record->employee_id)->firstOrFail();
        $this->authorize($employee, $actor);
        $this->requireReason($reason);
        if ($record instanceof ManualPunch) {
            if ($record->reverses_id !== null || ManualPunch::query()->where('reverses_id', $record->id)->exists()) {
                throw ValidationException::withMessages(['review' => __('messages.office_attendance.already_withdrawn')]);
            }
            $date = $record->punched_at->toDateString();
            if ($record->role === 'check_out' && $record->punched_at->format('H:i') <= '02:00') {
                $date = $record->punched_at->copy()->subDay()->toDateString();
            }

            return DB::transaction(function () use ($record, $employee, $reason, $actor, $date): ManualPunch {
                $reversal = ManualPunch::create([
                    'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                    'punched_at' => $record->punched_at, 'role' => $record->role,
                    'reason' => $reason, 'created_by' => $actor->id, 'reverses_id' => $record->id,
                ]);
                $this->audit->log('attendance.manual_punch.withdrawn', $reversal, [
                    'before' => ['manual_punch_id' => $record->id], 'after' => ['reverses_id' => $record->id],
                    'reason' => $reason,
                ], $actor);
                $this->dispatch($employee, $date);

                return $reversal;
            });
        }
        if ($record->decision === 'pick_shift') {
            return $this->withdrawShift($record, $employee, $reason, $actor);
        }
        if ($record->decision !== 'confirm_absence'
            || DayDecision::query()->where('employee_id', $employee->id)->whereDate('work_date', $record->work_date)
                ->whereIn('decision', ['confirm_absence', 'withdraw_confirmation'])
                ->orderByDesc('created_at')->orderByDesc('id')->value('id') != $record->id) {
            throw ValidationException::withMessages(['review' => __('messages.office_attendance.already_withdrawn')]);
        }
        $date = $record->work_date->toDateString();

        return DB::transaction(function () use ($record, $employee, $reason, $actor, $date): DayDecision {
            $withdrawal = DayDecision::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'work_date' => $date, 'decision' => 'withdraw_confirmation',
                'reason' => $reason, 'created_by' => $actor->id,
                'before' => ['decision_id' => $record->id, 'confirmed' => true],
                'after' => ['confirmed' => false],
            ]);
            $this->audit->log('attendance.day.withdrawn', $withdrawal, [
                'before' => $withdrawal->before, 'after' => $withdrawal->after, 'reason' => $reason,
            ], $actor);
            $this->dispatch($employee, $date);

            return $withdrawal;
        });
    }

    private function withdrawShift(DayDecision $record, Employee $employee, string $reason, User $actor): DayDecision
    {
        $last = DayDecision::query()->where('employee_id', $employee->id)->whereDate('work_date', $record->work_date)
            ->whereIn('decision', ['pick_shift', 'withdraw_shift'])->orderByDesc('created_at')->orderByDesc('id')->first();
        if ($last?->id !== $record->id) {
            throw ValidationException::withMessages(['review' => __('messages.office_attendance.already_withdrawn')]);
        }

        return DB::transaction(function () use ($record, $employee, $reason, $actor): DayDecision {
            $withdrawal = DayDecision::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'work_date' => $record->work_date, 'decision' => 'withdraw_shift',
                'reason' => $reason, 'created_by' => $actor->id,
                'before' => ['decision_id' => $record->id, 'shift_start' => $record->shift_start],
                'after' => ['shift_start' => null],
            ]);
            $this->audit->log('attendance.shift.withdrawn', $withdrawal, [
                'before' => $withdrawal->before, 'after' => $withdrawal->after, 'reason' => $reason,
            ], $actor);
            $this->dispatch($employee, $record->work_date->toDateString());

            return $withdrawal;
        });
    }

    private function reviewable(Employee $employee, string $date): Day
    {
        $day = Day::query()->where('company_id', $employee->company_id)->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)->whereNull('superseded_by_run_id')->first();
        if ($day === null || ($day->reviewed && $day->status !== 'missing_punch')
            || ! in_array($day->status, ['absent', 'missing_punch'], true)) {
            throw ValidationException::withMessages(['review' => __('messages.office_attendance.not_reviewable')]);
        }

        return $day;
    }

    private function authorize(Employee $employee, User $actor): void
    {
        $company = Company::findOrFail($employee->company_id);
        abort_unless(auth()->id() === $actor->id && $actor->is_active
            && in_array($actor->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && $actor->canAccessTenant($company) && app(TenantContext::class)->companyId() === $company->id, 403);
    }

    private function requireReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('messages.office_attendance.reason_required')]);
        }
    }

    private function dispatch(Employee $employee, string $date): void
    {
        AssessDaysJob::dispatch($employee->company_id, [
            'employee_ids' => [$employee->id], 'from' => $date, 'to' => $date,
        ], 'review')->afterCommit();
    }
}
