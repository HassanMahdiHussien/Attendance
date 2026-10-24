<?php

namespace App\Services\Employees;

use App\Models\Attendance\DataIssue;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\User;
use App\Services\Attendance\DataIssues;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PatternRule
{
    public function __construct(private EmployeeHistory $history, private DataIssues $issues) {}

    public function apply(Employee $employee, CarbonInterface|string $effectiveFrom, ?int $batchId = null): ?EmployeeVersion
    {
        return DB::transaction(function () use ($employee, $effectiveFrom, $batchId): ?EmployeeVersion {
            $employee = Employee::query()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
            if ($employee->work_pattern !== null) {
                return null;
            }
            $values = $employee->versions()->exists()
                ? $this->history->asOf($employee, $effectiveFrom)
                : $this->history->valuesFrom($employee);
            if ($values['work_pattern'] !== null) {
                return null;
            }
            $rule = $this->suggest($values);
            if ($rule === null) {
                return null;
            }
            // A rule fills a pattern that was never set, so it describes the whole employment, not a change from
            // the import date: punches imported for earlier months must be assessed with it too.
            $from = CarbonImmutable::parse($effectiveFrom);
            if ($employee->hire_date !== null && $employee->hire_date->lt($from)) {
                $from = CarbonImmutable::parse($employee->hire_date);
            }
            $version = $this->history->record(
                $employee, ['work_pattern' => $rule['pattern']], $from, 'rule',
                reason: 'pattern rule: '.$rule['name'], batchId: $batchId,
            );
            if ($version !== null && $this->needsReview((string) ($values['position'] ?? ''))) {
                $this->issues->open($employee->company, 'pattern_review', 'employee', 'employee:'.$employee->id,
                    details: ['rule' => $rule['name']]);
            }

            return $version;
        });
    }

    public function suggest(array $values): ?array
    {
        if (($values['work_pattern'] ?? null) !== null) {
            return null;
        }
        foreach (config('attendance.pattern_rules', []) as $rule) {
            if ($this->matches($rule, $values)) {
                return $rule;
            }
        }

        return null;
    }

    public function source(Employee $employee): ?string
    {
        $version = $employee->versions()->orderByDesc('effective_from')->orderByDesc('id')
            ->get(['source', 'changes'])->first(fn (EmployeeVersion $version): bool => array_key_exists('work_pattern', $version->changes ?? []));

        return $version?->source;
    }

    public function resolveReview(Employee $employee, User $actor): void
    {
        $issue = DataIssue::query()->where('company_id', $employee->company_id)
            ->where('open_key', 'pattern_review|employee|employee:'.$employee->id)->first();
        if ($issue !== null) {
            $this->issues->resolve($issue, $actor, __('messages.employee_history.pattern_review_resolved'));
        }
    }

    private function matches(array $rule, array $values): bool
    {
        $value = (string) ($values[$rule['field']] ?? '');

        return isset($rule['regex'])
            ? preg_match($rule['regex'], $value) === 1
            : strcasecmp($value, (string) ($rule['equals'] ?? '')) === 0;
    }

    private function needsReview(string $position): bool
    {
        foreach (config('attendance.pattern_review_positions', []) as $regex) {
            if (preg_match($regex, $position) === 1) {
                return true;
            }
        }

        return false;
    }
}
