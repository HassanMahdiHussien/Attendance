<?php

namespace App\Services\Employees;

use App\Enums\EmployeeGender;
use App\Enums\EmployeeWorkPattern;
use App\Events\EmployeeVersionChanged;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeeHistory
{
    public const FIELDS = [
        'name', 'name_en', 'department', 'department_en', 'position', 'position_en',
        'hire_date', 'branch_id', 'email', 'gender', 'work_pattern', 'rest_days',
        'schedule_start', 'schedule_end', 'manager_employee_id', 'manager_name',
        'fallback_notification_email',
    ];

    public function __construct(private AuditLogger $audit) {}

    public function asOf(Employee $employee, CarbonInterface|string $date): array
    {
        $day = CarbonImmutable::parse($date)->toDateString();
        $values = array_fill_keys(self::FIELDS, null);
        $resolved = [];
        foreach ($employee->versions()->whereDate('effective_from', '<=', $day)
            ->orderByDesc('effective_from')->orderByDesc('id')->get() as $version) {
            foreach ($version->changes as $field => $value) {
                if (array_key_exists($field, $values) && ! isset($resolved[$field])) {
                    $values[$field] = $value;
                    $resolved[$field] = true;
                }
            }
            if (count($resolved) === count(self::FIELDS)) {
                break;
            }
        }

        return $values;
    }

    public function create(array $identity, array $values, CarbonInterface|string $effectiveFrom, string $source, ?User $actor = null, ?string $reason = null, ?int $batchId = null): Employee
    {
        return DB::transaction(function () use ($identity, $values, $effectiveFrom, $source, $actor, $reason, $batchId): Employee {
            $employee = Employee::create($identity);
            // Required identity fields need a present-day baseline even when a change starts in the future.
            $this->record($employee, $this->valuesFrom($employee), today(), 'initial', $actor, batchId: $batchId);
            $this->record($employee, $values, $effectiveFrom, $source, $actor, $reason, $batchId);

            return $employee->fresh();
        });
    }

    public function record(Employee $employee, array $values, CarbonInterface|string $effectiveFrom, string $source, ?User $actor = null, ?string $reason = null, ?int $batchId = null): ?EmployeeVersion
    {
        return DB::transaction(function () use ($employee, $values, $effectiveFrom, $source, $actor, $reason, $batchId): ?EmployeeVersion {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $this->ensureInitial($employee, $actor, $source === 'initial' ? $batchId : null);
            $date = CarbonImmutable::parse($effectiveFrom)->startOfDay();
            $before = $this->asOf($employee, $date);
            $changes = [];
            $previous = [];
            foreach (Arr::only($values, self::FIELDS) as $field => $value) {
                $value = $this->normalise($field, $value);
                $this->validateValue($employee, $field, $value);
                if ($before[$field] !== $value) {
                    $changes[$field] = $value;
                    $previous[$field] = $before[$field];
                }
            }
            if ($changes === []) {
                return null;
            }
            $version = EmployeeVersion::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->id,
                'effective_from' => $date->toDateString(),
                'changes' => $changes,
                'previous' => $previous,
                'source' => $source,
                'reason' => $reason,
                'changed_by' => $actor?->id,
                'employee_import_batch_id' => $batchId,
            ]);
            $this->recompute($employee);
            $this->audit->log('employee.version.recorded', $employee, [
                'effective_from' => $date->toDateString(), 'before' => $previous, 'after' => $changes,
                'source' => $source, 'reason' => $reason, 'version_id' => $version->id,
            ], $actor);
            event(new EmployeeVersionChanged($employee->fresh(), $date));

            return $version;
        });
    }

    public function recompute(Employee $employee): void
    {
        $values = $this->asOf($employee, today());
        $current = $this->valuesFrom($employee);
        $updates = [];
        foreach ($values as $field => $value) {
            if ($value !== null && $current[$field] !== $value) {
                $updates[$field] = $value;
            }
            if ($value === null && ($current[$field] !== null || $employee->getRawOriginal($field) === '')
                && ! in_array($field, ['name', 'hire_date', 'branch_id', 'gender'], true)) {
                $updates[$field] = null;
            }
        }
        if ($updates !== []) {
            $employee->fill($updates)->save();
        }
    }

    public function valuesFrom(Employee $employee): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[$field] = $this->normalise($field, $employee->{$field});
        }

        return $values;
    }

    private function ensureInitial(Employee $employee, ?User $actor, ?int $batchId): void
    {
        if ($employee->versions()->exists()) {
            return;
        }
        $effective = $employee->hire_date?->toDateString() ?? $employee->created_at?->toDateString() ?? today()->toDateString();
        if ($effective > today()->toDateString()) {
            $effective = today()->toDateString();
        }
        $version = EmployeeVersion::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'effective_from' => $effective,
            'changes' => $this->valuesFrom($employee),
            'previous' => [],
            'source' => 'initial',
            'changed_by' => $actor?->id,
            'employee_import_batch_id' => $batchId,
        ]);
        $this->audit->log('employee.version.recorded', $employee, [
            'effective_from' => $version->effective_from->toDateString(),
            'before' => [], 'after' => $version->changes,
            'source' => 'initial', 'version_id' => $version->id,
        ], $actor);
        event(new EmployeeVersionChanged($employee, CarbonImmutable::parse($version->effective_from)));
    }

    private function normalise(string $field, mixed $value): mixed
    {
        if ($value instanceof EmployeeGender || $value instanceof EmployeeWorkPattern) {
            return $value->value;
        }
        if ($value === '' && $field !== 'name') {
            return null;
        }
        if ($value === null) {
            return null;
        }
        if ($field === 'rest_days') {
            $days = array_values(array_unique(array_map('intval', (array) $value)));
            sort($days);
            if (array_filter($days, fn (int $day): bool => $day < 1 || $day > 7)) {
                throw ValidationException::withMessages(['rest_days' => __('messages.employee_history.rest_days_invalid')]);
            }

            return $days === [] ? null : $days;
        }
        if ($field === 'hire_date') {
            return CarbonImmutable::parse($value)->toDateString();
        }
        if (in_array($field, ['schedule_start', 'schedule_end'], true)) {
            return CarbonImmutable::parse($value)->format('H:i:s');
        }
        if (in_array($field, ['branch_id', 'manager_employee_id'], true)) {
            return (int) $value;
        }

        return $value;
    }

    private function validateValue(Employee $employee, string $field, mixed $value): void
    {
        if (in_array($field, ['name', 'hire_date', 'branch_id', 'gender'], true) && ($value === null || $value === '')) {
            throw ValidationException::withMessages([$field => __('messages.employee_history.required_identity')]);
        }
        if ($field === 'branch_id' && ! Branch::query()->whereKey($value)->where('company_id', $employee->company_id)->exists()) {
            throw ValidationException::withMessages([$field => __('messages.employee_history.invalid_reference')]);
        }
        if ($field === 'manager_employee_id' && $value !== null
            && ! Employee::query()->whereKey($value)->where('company_id', $employee->company_id)->exists()) {
            throw ValidationException::withMessages([$field => __('messages.employee_history.invalid_reference')]);
        }
        if ($field === 'work_pattern' && $value !== null && EmployeeWorkPattern::tryFrom((string) $value) === null) {
            throw ValidationException::withMessages([$field => __('messages.employee_history.invalid_pattern')]);
        }
        if ($field === 'gender' && EmployeeGender::tryFrom((string) $value) === null) {
            throw ValidationException::withMessages([$field => __('messages.employee.gender_invalid')]);
        }
    }
}
