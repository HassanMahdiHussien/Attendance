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
        $this->reviewable($employee, $date);

        return DB::transaction(function () use ($employee, $date, $time, $role, $reason, $actor): ManualPunch {
            $punch = ManualPunch::create([
                'company_id' => $employee->company_id, 'employee_id' => $employee->id,
                'punched_at' => $date.' '.$time.':00', 'role' => $role,
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
        if ($record->decision !== 'confirm_absence'
            || DayDecision::query()->where('employee_id', $employee->id)->whereDate('work_date', $record->work_date)
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

    private function reviewable(Employee $employee, string $date): Day
    {
        $day = Day::query()->where('company_id', $employee->company_id)->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)->whereNull('superseded_by_run_id')->first();
        if ($day === null || $day->reviewed || ! in_array($day->status, ['absent', 'missing_punch'], true)) {
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
