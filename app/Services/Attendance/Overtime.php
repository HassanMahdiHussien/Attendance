<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Day;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\EntitlementConsumption;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ResultCache;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Collection;

class Overtime
{
    private const DAY_COLUMNS = ['id', 'employee_id', 'work_date', 'overtime_minutes', 'overtime_basis', 'early_arrival_minutes'];

    public function __construct(private AttendanceSummary $summary, private ResultCache $cache) {}

    public function totals(ReportFilters $filters, ?string $asOf = null): array
    {
        if ($asOf !== null) {
            return $this->totalsLive($filters, $asOf);
        }

        return $this->cache->remember('overtime_totals', $filters, fn (): array => $this->totalsLive($filters));
    }

    public function totalsLive(ReportFilters $filters, ?string $asOf = null): array
    {
        $totals = $this->emptyRow();
        $totals['employee_ids'] = [];
        $totals['entitled_employees'] = 0;
        foreach ($this->perEmployee($filters, $asOf) as $row) {
            $totals['employee_ids'][] = $row['employee_id'];
            if ($row['entitlements_earned'] > 0) {
                $totals['entitled_employees']++;
            }
            foreach (['overtime_minutes', 'early_arrival_days', 'entitlements_earned',
                'entitlements_used', 'entitlements_available_now', 'entitlements_overdue_now'] as $key) {
                $totals[$key] += $row[$key];
            }
            foreach ($row['overtime_by_basis'] as $basis => $minutes) {
                $totals['overtime_by_basis'][$basis] += $minutes;
            }
            $totals['day_ids'] = array_merge($totals['day_ids'], $row['day_ids']);
            $totals['entitlement_ids'] = array_merge($totals['entitlement_ids'], $row['entitlement_ids']);
        }

        return $totals;
    }

    public function perEmployee(ReportFilters $filters, ?string $asOf = null): Generator
    {
        $this->assertCompany($filters);
        $asOf ??= today('Asia/Baghdad')->toDateString();
        $rows = [];
        foreach ($this->summary->selectedDays($filters, columns: self::DAY_COLUMNS) as $day) {
            $id = (int) $day->employee_id;
            $rows[$id] ??= $this->emptyRow() + ['employee_id' => $id];
            $this->addDay($rows[$id], $day);
        }
        $this->addEntitlements($filters, $rows, $asOf, true);
        $this->addEntitlements($filters, $rows, $asOf, false);
        ksort($rows, SORT_NUMERIC);
        foreach ($rows as $row) {
            yield $row;
        }
    }

    public function perDay(ReportFilters $filters, int $employeeId): Generator
    {
        $this->assertCompany($filters);
        abort_if($filters->employeeIds !== [] && ! in_array($employeeId, $filters->employeeIds, true), 403);
        Employee::query()->where('company_id', $filters->companyId)->findOrFail($employeeId);
        foreach ($this->summary->selectedDays($filters, [$employeeId], self::DAY_COLUMNS) as $day) {
            yield [
                'day_id' => (int) $day->id, 'work_date' => $day->work_date->toDateString(),
                'overtime_minutes' => $day->overtime_minutes === null ? null : (int) $day->overtime_minutes,
                'overtime_basis' => $day->overtime_basis,
                'early_arrival_minutes' => $day->early_arrival_minutes === null ? null : (int) $day->early_arrival_minutes,
            ];
        }
    }

    public function dayRows(ReportFilters $filters, array $dayIds = []): Generator
    {
        $this->assertCompany($filters);
        foreach ($this->summary->selectedDays($filters, columns: self::DAY_COLUMNS, dayIds: $dayIds) as $day) {
            yield [
                'day_id' => (int) $day->id, 'employee_id' => (int) $day->employee_id,
                'work_date' => $day->work_date->toDateString(),
                'overtime_minutes' => $day->overtime_minutes === null ? null : (int) $day->overtime_minutes,
                'overtime_basis' => $day->overtime_basis,
                'early_arrival_minutes' => $day->early_arrival_minutes === null ? null : (int) $day->early_arrival_minutes,
            ];
        }
    }

    public function entitlements(ReportFilters $filters, ?string $asOf = null, array $dayIds = []): Generator
    {
        $this->assertCompany($filters);
        $asOf ??= today('Asia/Baghdad')->toDateString();
        foreach ($this->matchingEntitlements($filters, $filters, dayIds: $dayIds) as $entitlements) {
            yield from $this->entitlementRows($filters, $entitlements, $asOf);
        }
    }

    public function consumptionsFor(ReportFilters $filters, array $entitlementIds): array
    {
        $this->assertCompany($filters);
        if ($entitlementIds === []) {
            return [];
        }

        return EntitlementConsumption::query()->with('request')
            ->where('company_id', $filters->companyId)->whereIn('entitlement_id', $entitlementIds)
            ->whereNull('superseded_by_run_id')->orderBy('id')->get()
            ->map(fn (EntitlementConsumption $consumption): array => [
                'entitlement_id' => (int) $consumption->entitlement_id,
                'request_id' => (int) $consumption->request_id,
                'external_id' => $consumption->request?->external_id,
                'quantity' => (float) $consumption->quantity,
            ])->all();
    }

    public function drillDownDayIds(ReportFilters $filters, string $basis): array
    {
        abort_unless(in_array($basis, ['after_end', 'rest_day', 'holiday'], true), 404);
        $this->assertCompany($filters);
        $ids = [];
        foreach ($this->summary->selectedDays($filters, columns: self::DAY_COLUMNS) as $day) {
            if ($day->overtime_basis === $basis && (int) $day->overtime_minutes > 0) {
                $ids[] = (int) $day->id;
            }
        }

        return $ids;
    }

    public function drillDownEmployeeIds(ReportFilters $filters, string $tile, ?string $asOf = null): array
    {
        abort_unless(in_array($tile, ['overtime_minutes', 'early_arrival_days', 'entitled_employees', 'entitlements_earned',
            'entitlements_available_now', 'entitlements_overdue_now'], true), 404);
        $ids = [];
        foreach ($this->perEmployee($filters, $asOf) as $row) {
            if (($tile === 'entitled_employees' ? $row['entitlements_earned'] : $row[$tile]) > 0) {
                $ids[] = $row['employee_id'];
            }
        }

        return $ids;
    }

    private function addDay(array &$row, Day $day): void
    {
        $row['day_ids'][] = (int) $day->id;
        $minutes = (int) ($day->overtime_minutes ?? 0);
        $row['overtime_minutes'] += $minutes;
        if ($day->overtime_basis !== null) {
            $row['overtime_by_basis'][$day->overtime_basis] += $minutes;
        }
        if ((int) ($day->early_arrival_minutes ?? 0) > 0) {
            $row['early_arrival_days']++;
        }
    }

    private function entitlementRows(ReportFilters $filters, Collection $entitlements, string $asOf): Generator
    {
        $entitlements = $entitlements->sortBy([['work_date', 'asc'], ['id', 'asc']]);
        $spent = EntitlementConsumption::query()->where('company_id', $filters->companyId)
            ->whereIn('entitlement_id', $entitlements->pluck('id'))
            ->whereNull('superseded_by_run_id')
            ->selectRaw('entitlement_id, SUM(quantity) AS quantity')
            ->groupBy('entitlement_id')->get()->keyBy('entitlement_id');
        foreach ($entitlements as $entitlement) {
            $earned = (float) $entitlement->quantity;
            $used = (float) ($spent[$entitlement->id]->quantity ?? 0);
            $available = max(0.0, $earned - $used);
            $date = CarbonImmutable::parse($entitlement->work_date->toDateString(), 'Asia/Baghdad');
            $alertOn = $date->subDays((int) $date->format('w'))->addWeeks(2)->toDateString();
            yield [
                'id' => (int) $entitlement->id, 'employee_id' => (int) $entitlement->employee_id,
                'source_day_id' => (int) $entitlement->source_day_id,
                'work_date' => $entitlement->work_date->toDateString(),
                'basis' => $entitlement->basis, 'holiday' => (bool) $entitlement->holiday,
                'earned' => $earned, 'used' => $used, 'available' => $available,
                'overdue' => $entitlement->work_date->toDateString() <= $asOf && $asOf >= $alertOn ? $available : 0.0,
                'alert_on' => $alertOn,
            ];
        }
    }

    private function matchingEntitlements(ReportFilters $filters, ReportFilters $sourceFilters, ?string $through = null, array $dayIds = []): Generator
    {
        $query = RestDayEntitlement::query()->where('company_id', $filters->companyId)
            ->whereNull('superseded_by_run_id')
            ->when($dayIds !== [], fn ($query) => $query->whereIn('source_day_id', $dayIds))
            ->when($filters->employeeIds !== [], fn ($query) => $query->whereIn('employee_id', $filters->employeeIds))
            ->when($through !== null, fn ($query) => $query->where('work_date', '<=', $through));
        $lastId = 0;
        while (true) {
            $entitlements = (clone $query)->where('id', '>', $lastId)->orderBy('id')->limit(1000)->get();
            if ($entitlements->isEmpty()) {
                break;
            }
            $lastId = (int) $entitlements->last()->id;
            $sourceIds = $entitlements->pluck('source_day_id')->all();
            $matched = [];
            foreach ($this->summary->selectedDays($sourceFilters, columns: ['id', 'employee_id', 'work_date'],
                dayIds: $sourceIds) as $day) {
                $matched[(int) $day->id] = true;
            }
            $selected = $entitlements->filter(fn (RestDayEntitlement $entitlement): bool => isset($matched[$entitlement->source_day_id]));
            if ($selected->isNotEmpty()) {
                yield $selected;
            }
        }
    }

    private function addEntitlements(ReportFilters $filters, array &$rows, string $asOf, bool $period): void
    {
        $sourceFilters = $period ? $filters : ReportFilters::fromArray(Company::findOrFail($filters->companyId), array_replace(
            $filters->toArray(), ['from' => '2000-01-01', 'to' => $asOf],
        ));
        foreach ($this->matchingEntitlements($filters, $sourceFilters, $period ? null : $asOf) as $entitlements) {
            $this->mergeEntitlements($filters, $rows, $entitlements, $asOf, $period);
        }
    }

    private function mergeEntitlements(ReportFilters $filters, array &$rows, Collection $entitlements, string $asOf, bool $period): void
    {
        $spent = $period ? collect() : EntitlementConsumption::query()->where('company_id', $filters->companyId)
            ->whereIn('entitlement_id', $entitlements->pluck('id'))
            ->whereNull('superseded_by_run_id')
            ->selectRaw('entitlement_id, SUM(quantity) AS quantity')
            ->groupBy('entitlement_id')->get()->keyBy('entitlement_id');
        foreach ($entitlements as $entitlement) {
            $rows[$entitlement->employee_id] ??= $this->emptyRow() + ['employee_id' => (int) $entitlement->employee_id];
            $row = &$rows[$entitlement->employee_id];
            $earned = (float) $entitlement->quantity;
            if ($period) {
                $row['entitlements_earned'] += $earned;
                unset($row);

                continue;
            }
            $used = (float) ($spent[$entitlement->id]->quantity ?? 0);
            $available = max(0.0, $earned - $used);
            $row['entitlements_used'] += $used;
            $row['entitlements_available_now'] += $available;
            $row['entitlement_ids'][] = (int) $entitlement->id;
            $date = CarbonImmutable::parse($entitlement->work_date->toDateString(), 'Asia/Baghdad');
            $alertOn = $date->subDays((int) $date->format('w'))->addWeeks(2)->toDateString();
            if ($available > 0 && $asOf >= $alertOn) {
                $row['entitlements_overdue_now'] += $available;
            }
            unset($row);
        }
    }

    private function emptyRow(): array
    {
        return [
            'overtime_minutes' => 0,
            'overtime_by_basis' => ['after_end' => 0, 'rest_day' => 0, 'holiday' => 0],
            'early_arrival_days' => 0,
            'entitlements_earned' => 0.0,
            'entitlements_used' => 0.0,
            'entitlements_available_now' => 0.0,
            'entitlements_overdue_now' => 0.0,
            'day_ids' => [], 'entitlement_ids' => [],
        ];
    }

    private function assertCompany(ReportFilters $filters): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $filters->companyId, 403);
    }
}
