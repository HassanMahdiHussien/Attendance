<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Day;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\EntitlementConsumption;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Generator;

class Overtime
{
    public function __construct(private AttendanceSummary $summary) {}

    public function totals(ReportFilters $filters, ?string $asOf = null): array
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
        $dayIds = [];
        foreach ($this->summary->selectedDays($filters) as $day) {
            $id = (int) $day->employee_id;
            $rows[$id] ??= $this->emptyRow() + ['employee_id' => $id];
            $this->addDay($rows[$id], $day);
            $dayIds[] = $day->id;
            if (count($dayIds) >= 1000) {
                $this->addEntitlements($filters, $rows, $dayIds, $asOf, true);
                $dayIds = [];
            }
        }
        if ($dayIds !== []) {
            $this->addEntitlements($filters, $rows, $dayIds, $asOf, true);
        }
        $currentFilters = ReportFilters::fromArray(Company::findOrFail($filters->companyId), array_replace(
            $filters->toArray(), ['from' => '2000-01-01', 'to' => $asOf],
        ));
        $dayIds = [];
        foreach ($this->summary->selectedDays($currentFilters) as $day) {
            $id = (int) $day->employee_id;
            $rows[$id] ??= $this->emptyRow() + ['employee_id' => $id];
            $dayIds[] = $day->id;
            if (count($dayIds) >= 1000) {
                $this->addEntitlements($filters, $rows, $dayIds, $asOf, false);
                $dayIds = [];
            }
        }
        if ($dayIds !== []) {
            $this->addEntitlements($filters, $rows, $dayIds, $asOf, false);
        }
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
        foreach ($this->summary->selectedDays($filters, [$employeeId]) as $day) {
            yield [
                'day_id' => (int) $day->id, 'work_date' => $day->work_date->toDateString(),
                'overtime_minutes' => $day->overtime_minutes === null ? null : (int) $day->overtime_minutes,
                'overtime_basis' => $day->overtime_basis,
                'early_arrival_minutes' => $day->early_arrival_minutes === null ? null : (int) $day->early_arrival_minutes,
            ];
        }
    }

    public function dayRows(ReportFilters $filters): Generator
    {
        $this->assertCompany($filters);
        foreach ($this->summary->selectedDays($filters) as $day) {
            yield [
                'day_id' => (int) $day->id, 'employee_id' => (int) $day->employee_id,
                'work_date' => $day->work_date->toDateString(),
                'overtime_minutes' => $day->overtime_minutes === null ? null : (int) $day->overtime_minutes,
                'overtime_basis' => $day->overtime_basis,
                'early_arrival_minutes' => $day->early_arrival_minutes === null ? null : (int) $day->early_arrival_minutes,
            ];
        }
    }

    public function entitlements(ReportFilters $filters, ?string $asOf = null): Generator
    {
        $this->assertCompany($filters);
        $asOf ??= today('Asia/Baghdad')->toDateString();
        $dayIds = [];
        foreach ($this->summary->selectedDays($filters) as $day) {
            $dayIds[] = $day->id;
            if (count($dayIds) >= 1000) {
                yield from $this->entitlementRows($filters, $dayIds, $asOf);
                $dayIds = [];
            }
        }
        if ($dayIds !== []) {
            yield from $this->entitlementRows($filters, $dayIds, $asOf);
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
        foreach ($this->summary->selectedDays($filters) as $day) {
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

    private function entitlementRows(ReportFilters $filters, array $dayIds, string $asOf): Generator
    {
        $entitlements = RestDayEntitlement::query()->where('company_id', $filters->companyId)
            ->whereIn('source_day_id', $dayIds)->whereNull('superseded_by_run_id')
            ->orderBy('work_date')->orderBy('id')->get();
        if ($entitlements->isEmpty()) {
            return;
        }
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

    private function addEntitlements(ReportFilters $filters, array &$rows, array $dayIds, string $asOf, bool $period): void
    {
        $entitlements = RestDayEntitlement::query()->where('company_id', $filters->companyId)
            ->whereIn('source_day_id', $dayIds)->whereNull('superseded_by_run_id')
            ->when(! $period, fn ($query) => $query->where('work_date', '<=', $asOf))
            ->orderBy('id')->get();
        if ($entitlements->isEmpty()) {
            return;
        }
        $spent = $period ? collect() : EntitlementConsumption::query()->where('company_id', $filters->companyId)
            ->whereIn('entitlement_id', $entitlements->pluck('id'))
            ->whereNull('superseded_by_run_id')
            ->selectRaw('entitlement_id, SUM(quantity) AS quantity')
            ->groupBy('entitlement_id')->get()->keyBy('entitlement_id');
        foreach ($entitlements as $entitlement) {
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
