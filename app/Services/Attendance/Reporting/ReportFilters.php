<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

class ReportFilters
{
    public const STATUSES = [
        'present', 'absent', 'missing_punch', 'shift_unresolved', 'pending', 'rest_day',
        'rest_day_worked', 'holiday', 'holiday_worked', 'not_employed', 'pattern_missing', 'device_outage',
    ];

    private function __construct(
        public readonly int $companyId,
        public readonly string $from,
        public readonly string $to,
        public readonly array $employeeIds = [],
        public readonly array $branchIds = [],
        public readonly array $departments = [],
        public readonly array $managerEmployeeIds = [],
        public readonly array $workPatterns = [],
        public readonly array $statuses = [],
        public readonly ?string $caseState = null,
        public readonly ?string $caseIssueType = null,
    ) {}

    public static function fromArray(Company $company, array $input): self
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        if (isset($input['company_id']) && (int) $input['company_id'] !== (int) $company->id) {
            throw ValidationException::withMessages(['company_id' => __('messages.reporting.invalid_filters')]);
        }
        $today = CarbonImmutable::now('Asia/Baghdad')->toDateString();
        $from = (string) ($input['from'] ?? substr($today, 0, 8).'01');
        $to = (string) ($input['to'] ?? $today);
        if (! self::dateValid($from) || ! self::dateValid($to) || $from > $to) {
            throw ValidationException::withMessages(['period' => __('messages.reporting.invalid_period')]);
        }

        $ids = self::numbers($input['employee_ids'] ?? [], 'employee_ids');
        $branches = self::numbers($input['branch_ids'] ?? [], 'branch_ids');
        $managers = self::numbers($input['manager_employee_ids'] ?? [], 'manager_employee_ids');
        $departments = self::strings($input['departments'] ?? [], 'departments');
        $patterns = self::strings($input['work_patterns'] ?? [], 'work_patterns');
        $statuses = self::strings($input['statuses'] ?? [], 'statuses');
        $caseState = $input['case_state'] ?? null;
        $caseIssueType = $input['case_issue_type'] ?? null;

        if (($ids !== [] && Employee::query()->where('company_id', $company->id)->whereIn('id', $ids)->count() !== count($ids))
            || ($managers !== [] && Employee::query()->where('company_id', $company->id)->whereIn('id', $managers)->count() !== count($managers))
            || ($branches !== [] && Branch::query()->where('company_id', $company->id)->whereIn('id', $branches)->count() !== count($branches))
            || array_diff($patterns, ['office', 'shift', 'security']) !== []
            || array_diff($statuses, self::STATUSES) !== []
            || ($caseState !== null && ! in_array($caseState, ['open_cases', 'open', 'waiting_manager', 'waiting_approver', 'waiting_hr', 'closed'], true))
            || ($caseIssueType !== null && ! in_array($caseIssueType, ['absence', 'late', 'early', 'exit', 'missing_punch'], true))) {
            throw ValidationException::withMessages(['filters' => __('messages.reporting.invalid_filters')]);
        }

        return new self((int) $company->id, $from, $to, $ids, $branches, $departments, $managers, $patterns, $statuses,
            $caseState, $caseIssueType);
    }

    public function toArray(): array
    {
        return [
            'company_id' => $this->companyId, 'from' => $this->from, 'to' => $this->to,
            'employee_ids' => $this->employeeIds, 'branch_ids' => $this->branchIds,
            'departments' => $this->departments, 'manager_employee_ids' => $this->managerEmployeeIds,
            'work_patterns' => $this->workPatterns, 'statuses' => $this->statuses,
        ] + ($this->caseState === null ? [] : ['case_state' => $this->caseState])
            + ($this->caseIssueType === null ? [] : ['case_issue_type' => $this->caseIssueType]);
    }

    private static function dateValid(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    private static function numbers(mixed $values, string $key): array
    {
        if (! is_array($values)) {
            throw ValidationException::withMessages([$key => __('messages.reporting.invalid_filters')]);
        }
        $result = [];
        foreach ($values as $value) {
            if (! ctype_digit((string) $value) || (int) $value < 1) {
                throw ValidationException::withMessages([$key => __('messages.reporting.invalid_filters')]);
            }
            $result[] = (int) $value;
        }

        $result = array_values(array_unique($result));
        sort($result, SORT_NUMERIC);

        return $result;
    }

    private static function strings(mixed $values, string $key): array
    {
        if (! is_array($values)) {
            throw ValidationException::withMessages([$key => __('messages.reporting.invalid_filters')]);
        }
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw ValidationException::withMessages([$key => __('messages.reporting.invalid_filters')]);
            }
        }

        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}
