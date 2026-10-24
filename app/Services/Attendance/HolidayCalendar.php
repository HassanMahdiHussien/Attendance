<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidayVersion;
use App\Models\Company;
use App\Models\EmployeeVersion;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class HolidayCalendar
{
    private array $byDate = [];

    public function load(Company $company, string $from, string $to): self
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $this->byDate = [];
        $holidays = Holiday::query()->with('currentVersion')->where('company_id', $company->id)
            ->where('status', 'approved')->get();
        foreach ($holidays as $holiday) {
            $version = $holiday->currentVersion;
            if ($version?->status !== 'approved') {
                continue;
            }
            foreach ($version->ranges as $range) {
                $start = max($from, $range['from']);
                $end = min($to, $range['to']);
                for ($date = $start; $date <= $end; $date = CarbonImmutable::parse($date)->addDay()->toDateString()) {
                    $this->byDate[$date][$holiday->id] = $version;
                }
            }
        }

        return $this;
    }

    public function idsFor(string $date, array $values): array
    {
        $ids = [];
        foreach ($this->byDate[$date] ?? [] as $id => $version) {
            if (self::matches($version, $values)) {
                $ids[] = (int) $id;
            }
        }
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public static function matches(HolidayVersion $version, array $values): bool
    {
        $entities = $version->legal_entities ?? [];
        $branches = $version->branch_ids ?? [];
        $patterns = $version->work_patterns ?? [];

        return ($entities === [] || in_array(mb_strtolower(trim((string) ($values['legal_entity'] ?? ''))),
            array_map(fn (string $entity): string => mb_strtolower(trim($entity)), $entities), true))
            && ($branches === [] || in_array((int) ($values['branch_id'] ?? 0), array_map('intval', $branches), true))
            && ($patterns === [] || in_array($values['work_pattern'] ?? null, $patterns, true));
    }

    public function forEmployeeDates(Company $company, Collection $employees, string $from, string $to): array
    {
        $this->load($company, $from, $to);
        if ($employees->isEmpty() || $this->byDate === []) {
            return [];
        }
        $versions = EmployeeVersion::query()->where('company_id', $company->id)
            ->whereIn('employee_id', $employees->pluck('id'))->whereDate('effective_from', '<=', $to)
            ->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
        $dates = array_keys($this->byDate);
        sort($dates);
        $result = [];
        foreach ($employees as $employee) {
            $history = $versions->get($employee->id, collect())->values();
            $values = $history->isEmpty() ? app(EmployeeHistory::class)->valuesFrom($employee)
                : array_fill_keys(EmployeeHistory::FIELDS, null);
            $index = 0;
            foreach ($dates as $date) {
                while ($index < $history->count() && $history[$index]->effective_from->toDateString() <= $date) {
                    foreach ($history[$index]->changes as $field => $value) {
                        $values[$field] = $value;
                    }
                    $index++;
                }
                $ids = $this->idsFor($date, $values);
                if ($ids !== []) {
                    $result[$employee->id][$date] = $ids;
                }
            }
        }

        return $result;
    }
}
