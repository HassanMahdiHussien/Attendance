<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Attendance\Day;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

class HomeOverview
{
    public const TASK_KEYS = ['absent_uncovered', 'missing_punch', 'shift_unresolved', 'short_hours_alerts', 'waiting_hr', 'data_issues'];

    public function tasks(array $tiles): array
    {
        $rows = [];
        foreach (self::TASK_KEYS as $key) {
            if (($tiles[$key] ?? 0) > 0) {
                $rows[] = ['key' => $key, 'count' => $tiles[$key]];
            }
        }

        return array_slice($rows, 0, 5);
    }

    public function previousFilters(Company $company, ReportFilters $filters): ReportFilters
    {
        $from = CarbonImmutable::parse($filters->from);
        $to = CarbonImmutable::parse($filters->to);
        $today = CarbonImmutable::now('Asia/Baghdad');
        if ($from->day === 1 && $to->isSameDay($from->endOfMonth()) && ! $from->isSameMonth($today)) {
            $previousFrom = $from->subMonthNoOverflow()->startOfMonth();
            $previousTo = $previousFrom->endOfMonth();
        } elseif ($from->day === 1 && $from->isSameMonth($today) && $to->isSameDay($today)) {
            $previousFrom = $from->subMonthNoOverflow()->startOfMonth();
            // 1–31 March compares with all of February, never spilling into March.
            $previousTo = $previousFrom->addDays((int) $from->diffInDays($to))->min($previousFrom->endOfMonth());
        } else {
            $previousTo = $from->subDay();
            $previousFrom = $previousTo->subDays($from->diffInDays($to));
        }

        return ReportFilters::fromArray($company, array_replace($filters->toArray(), [
            'from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString(),
        ]));
    }

    public function figures(array $current, array $previous): array
    {
        $currentTiles = ReportTiles::fromTotals($current);
        $previousTiles = ReportTiles::fromTotals($previous);
        $rate = static fn (array $totals): ?int => $totals['assessed_working_days'] > 0
            ? (int) round(100 * ($totals['days']['present'] + $totals['days']['field_work']) / $totals['assessed_working_days']) : null;
        $rows = [];
        foreach (['attendance_rate', 'late', 'early', 'remaining'] as $key) {
            $value = $key === 'attendance_rate' ? $rate($current) : $currentTiles[$key];
            $before = $key === 'attendance_rate' ? $rate($previous) : $previousTiles[$key];
            $difference = $value === null || $before === null ? null : $value - $before;
            $rows[] = ['key' => $key, 'value' => $value, 'difference' => $difference,
                'tone' => $difference === null || $difference === 0 ? 'same'
                    : (($key === 'attendance_rate' ? $difference > 0 : $difference < 0) ? 'better' : 'worse')];
        }

        return $rows;
    }

    public function topLate(ReportFilters $filters, array $employeeIds): array
    {
        abort_unless(app(TenantContext::class)->companyId() === $filters->companyId, 403);
        if ($employeeIds === []) {
            return [];
        }

        $days = Day::query()->where('attendance_days.company_id', $filters->companyId)
            ->join('employees', function (JoinClause $join) use ($filters): void {
                $join->on('employees.id', '=', 'attendance_days.employee_id')
                    ->where('employees.company_id', '=', $filters->companyId);
            })
            ->whereIn('attendance_days.employee_id', $employeeIds)
            ->whereBetween('attendance_days.work_date', [$filters->from, $filters->to])
            ->whereNull('attendance_days.superseded_by_run_id')
            ->where('attendance_days.status', 'present')->where('attendance_days.late_minutes', '>', 0);
        if ($filters->branchIds !== []) {
            $days->whereIn('attendance_days.branch_id', $filters->branchIds);
        }
        if ($filters->statuses !== [] && ! in_array('present', $filters->statuses, true)) {
            return [];
        }

        return $days->selectRaw('attendance_days.employee_id, employees.name, employees.employee_code, SUM(attendance_days.late_minutes) as minutes')
            ->groupBy('attendance_days.employee_id', 'employees.name', 'employees.employee_code')
            ->orderByDesc('minutes')->orderBy('attendance_days.employee_id')->limit(5)->get()->all();
    }

    public function latestFiles(array $sources): array
    {
        $cutoff = CarbonImmutable::now('Asia/Baghdad')->subDays(7);
        $rows = [];
        foreach ($sources as $source => $time) {
            $rows[] = ['source' => $source, 'time' => $time,
                'old' => $time === null || CarbonImmutable::parse($time)->lessThan($cutoff)];
        }

        return $rows;
    }
}
