<?php

namespace App\Services\Employees;

use App\Enums\EmployeeExitStatus;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ExitReview
{
    public function __construct(private AuditLogger $audit) {}

    public function pending(Company $company): Builder
    {
        Gate::authorize('importEmployees', $company);

        return Employee::query()->where('company_id', $company->id)->where('exit_status', EmployeeExitStatus::PendingReview->value);
    }

    public function start(Employee $employee, User $actor, ?EmployeeImportBatch $batch = null): void
    {
        $this->decide($employee, $actor, function (Employee $employee) use ($batch): void {
            if (! $employee->is_active) {
                throw ValidationException::withMessages(['employee' => __('messages.exit_review.inactive')]);
            }
            if ($employee->exit_status === EmployeeExitStatus::Confirmed) {
                throw ValidationException::withMessages(['employee' => __('messages.exit_review.already_confirmed')]);
            }
            $employee->exit_status = EmployeeExitStatus::PendingReview;
            $employee->last_working_day = null;
            $employee->exit_review_batch_id = $batch?->id;
        }, 'started');
    }

    public function reappear(Employee $employee, EmployeeImportBatch $batch, User $actor): void
    {
        $this->decide($employee, $actor, function (Employee $employee): void {
            if ($employee->exit_status !== EmployeeExitStatus::PendingReview) {
                return;
            }
            $employee->exit_status = EmployeeExitStatus::None;
            $employee->exit_review_batch_id = null;
        }, 'reappeared', 'Included in import batch '.$batch->id);
    }

    public function confirm(Employee $employee, CarbonInterface|string $lastWorkingDay, User $actor): void
    {
        $date = CarbonImmutable::parse($lastWorkingDay)->toDateString();
        $this->decide($employee, $actor, function (Employee $employee) use ($date): void {
            abort_unless($employee->exit_status === EmployeeExitStatus::PendingReview, 422);
            $employee->exit_status = EmployeeExitStatus::Confirmed;
            $employee->last_working_day = $date;
            $employee->is_active = $date >= today()->toDateString();
        }, 'confirmed');
    }

    public function dismiss(Employee $employee, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => __('messages.exit_review.reason_required')]);
        }
        $this->decide($employee, $actor, function (Employee $employee): void {
            abort_unless($employee->exit_status === EmployeeExitStatus::PendingReview, 422);
            $employee->exit_status = EmployeeExitStatus::None;
            $employee->last_working_day = null;
            $employee->exit_review_batch_id = null;
        }, 'dismissed', $reason);
    }

    public function applyDue(Employee $employee): bool
    {
        if ($employee->exit_status !== EmployeeExitStatus::Confirmed || ! $employee->is_active
            || $employee->last_working_day === null || $employee->last_working_day->toDateString() >= today()->toDateString()) {
            return false;
        }
        $employee->is_active = false;
        $employee->save();
        $this->audit->log('employee.exit.deactivated', $employee, [
            'before' => ['is_active' => true], 'after' => ['is_active' => false],
            'last_working_day' => $employee->last_working_day->toDateString(),
        ]);

        return true;
    }

    private function decide(Employee $employee, User $actor, callable $change, string $action, ?string $reason = null): void
    {
        Gate::forUser($actor)->authorize('update', $employee);
        DB::transaction(function () use ($employee, $actor, $change, $action, $reason): void {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $before = [
                'exit_status' => $employee->exit_status->value,
                'last_working_day' => $employee->last_working_day?->toDateString(),
                'is_active' => $employee->is_active,
                'exit_review_batch_id' => $employee->exit_review_batch_id,
            ];
            $change($employee);
            if (! $employee->isDirty()) {
                return;
            }
            $employee->save();
            $this->audit->log('employee.exit.'.$action, $employee, [
                'before' => $before,
                'after' => [
                    'exit_status' => $employee->exit_status->value,
                    'last_working_day' => $employee->last_working_day?->toDateString(),
                    'is_active' => $employee->is_active,
                    'exit_review_batch_id' => $employee->exit_review_batch_id,
                ],
                'reason' => $reason,
            ], $actor);
        });
    }
}
