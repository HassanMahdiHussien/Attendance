<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Employee;
use App\Services\Attendance\ShiftDisplay;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;

class TimeManagementReport
{
    public function __construct(private AttendanceSummary $summary, private EmployeeHistory $history) {}

    public function days(ReportFilters $filters): array
    {
        abort_unless(app(TenantContext::class)->companyId() === $filters->companyId, 403);
        $employees = Employee::query()->where('company_id', $filters->companyId)->get()->keyBy('id');
        $rows = [];

        foreach ($this->summary->selectedDays($filters) as $day) {
            if (($day->inputs['work_pattern'] ?? null) !== 'shift' || $day->first_in === null) {
                continue;
            }
            $night = ShiftDisplay::key('shift', $day->shift_start) === 'night';
            $friday = $day->work_date->isoWeekday() === 5;
            $holiday = $day->status === 'holiday_worked' || $day->overtime_basis === 'holiday';
            if (! $night && ! $friday && ! $holiday) {
                continue;
            }

            $employee = $employees->get($day->employee_id);
            if ($employee === null) {
                continue;
            }
            $historical = $this->history->asOf($employee, $day->work_date);
            $rows[] = [
                'employee_id' => (int) $employee->id,
                'employee_code' => $employee->employee_code,
                'employee_name' => $employee->name,
                'department' => $historical['department'] ?? $employee->department ?? '',
                'date' => $day->work_date->toDateString(),
                'night' => $night, 'friday' => $friday, 'holiday' => $holiday,
                'shift_start' => $day->shift_start,
                'shift_minutes' => $day->policy?->shift_minutes,
                'first_in' => $day->first_in,
                'last_out' => $day->last_out,
                'presence_minutes' => (int) ($day->presence_minutes ?? 0),
                'overtime_minutes' => (int) ($day->overtime_minutes ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['employee_code'], $b['employee_code'])
            ?: strcmp($a['date'], $b['date']) ?: ($a['employee_id'] <=> $b['employee_id']));

        return $rows;
    }

    public function totals(array $days): array
    {
        $employees = [];
        foreach ($days as $day) {
            $id = $day['employee_id'];
            $employees[$id] ??= [
                'employee_code' => $day['employee_code'], 'employee_name' => $day['employee_name'],
                'department' => $day['department'], 'night_count' => 0, 'night_minutes' => 0,
                'friday_count' => 0, 'friday_minutes' => 0, 'holiday_count' => 0,
                'holiday_minutes' => 0, 'overtime_minutes' => 0,
            ];
            foreach (['night', 'friday', 'holiday'] as $type) {
                if ($day[$type]) {
                    $employees[$id][$type.'_count']++;
                    $employees[$id][$type.'_minutes'] += $day['presence_minutes'];
                }
            }
            $employees[$id]['overtime_minutes'] += $day['overtime_minutes'];
        }

        return array_values($employees);
    }
}
