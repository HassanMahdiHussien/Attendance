<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\Meal;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestDecision;
use App\Services\Leave\LeaveExplanation;
use Generator;

class ExportData
{
    public const DAY_COLUMNS = [
        'employee_code', 'employee_name', 'date', 'status', 'first_in', 'last_out',
        'absence', 'late', 'early', 'exit', 'gross', 'leave', 'credit', 'remaining',
        'request_numbers', 'explanations',
    ];

    public const SUMMARY_COLUMNS = [
        'employee_code', 'employee_name', 'date', 'group', 'status', 'target_kind', 'request_count',
        'minutes', 'created', 'used', 'available', 'applied_minutes', 'request_numbers', 'explanations',
    ];

    public const MEAL_COLUMNS = ['employee_code', 'employee_name', 'department', 'date', 'meals', 'repetitions'];

    public const ISSUE_COLUMNS = ['employee_code', 'employee_name', 'issue_type', 'issues'];

    public function __construct(private AttendanceSummary $summary, private LastUpdate $lastUpdate, private LeaveExplanation $explanations) {}

    public function columns(string $kind): array
    {
        return match ($kind) {
            'leave_and_credit' => self::SUMMARY_COLUMNS,
            'restaurant_usage' => self::MEAL_COLUMNS,
            'data_issues' => self::ISSUE_COLUMNS,
            default => self::DAY_COLUMNS,
        };
    }

    public function header(AttendanceExport $export, Company $company, ReportFilters $filters): array
    {
        $requestedBy = $export->requester?->name ?? __('messages.export_ui.system');
        $freshness = $this->lastUpdate->for($filters);
        $branches = $filters->branchIds === [] ? __('messages.reporting_ui.all')
            : $company->branches()->whereIn('id', $filters->branchIds)->pluck('name')->implode(', ');
        $employees = $filters->employeeIds === [] ? __('messages.reporting_ui.all')
            : Employee::query()->whereIn('id', $filters->employeeIds)->pluck('employee_code')->implode(', ');
        $managers = $filters->managerEmployeeIds === [] ? __('messages.reporting_ui.all')
            : Employee::query()->whereIn('id', $filters->managerEmployeeIds)->pluck('employee_code')->implode(', ');

        return [
            [__('messages.export_ui.report'), __('messages.export_ui.kinds.'.$export->kind)],
            [__('messages.export_ui.company'), $company->name],
            [__('messages.export_ui.period'), $filters->from.' — '.$filters->to],
            [__('messages.export_ui.employees'), $employees],
            [__('messages.export_ui.branches'), $branches],
            [__('messages.export_ui.departments'), $filters->departments === [] ? __('messages.reporting_ui.all') : implode(', ', $filters->departments)],
            [__('messages.export_ui.managers'), $managers],
            [__('messages.export_ui.patterns'), $filters->workPatterns === [] ? __('messages.reporting_ui.all') : implode(', ', array_map(fn (string $value): string => __('messages.reporting_ui.patterns.'.$value), $filters->workPatterns))],
            [__('messages.export_ui.statuses'), $filters->statuses === [] ? __('messages.reporting_ui.all') : implode(', ', array_map(fn (string $value): string => __('messages.office_ui.statuses.'.$value), $filters->statuses))],
            [__('messages.export_ui.generated_at'), now('Asia/Baghdad')->format('Y-m-d H:i:s')],
            [__('messages.export_ui.generated_by'), $requestedBy],
            [__('messages.export_ui.last_update'), $freshness['runs']['reconciliation'] ?? $freshness['runs']['assessment'] ?? $freshness['sources']['punches'] ?? __('messages.export_ui.never')],
        ];
    }

    public function rows(AttendanceExport $export, ReportFilters $filters): Generator
    {
        if ($export->kind === 'restaurant_usage') {
            yield from $this->meals($filters);

            return;
        }
        if ($export->kind === 'data_issues') {
            yield from $this->issues($filters);

            return;
        }
        foreach ($this->summary->perEmployee($filters) as $employee) {
            $record = Employee::query()->whereKey($employee['employee_id'])->firstOrFail();
            if ($export->kind === 'leave_and_credit') {
                $dayFiltered = $filters->branchIds !== [] || $filters->departments !== []
                    || $filters->managerEmployeeIds !== [] || $filters->workPatterns !== [] || $filters->statuses !== [];
                $selectedDates = $dayFiltered ? collect(iterator_to_array($this->summary->perDay($filters, $record->id), false))
                    ->pluck('work_date')->flip() : collect();
                $requestDays = RequestDay::query()->where('employee_id', $record->id)
                    ->whereNull('superseded_by_run_id')->whereBetween('work_date', [$filters->from, $filters->to])->get();
                $requestIds = $requestDays->filter(fn (RequestDay $day): bool => ! $dayFiltered
                    || $selectedDates->has($day->work_date->toDateString()))->pluck('request_id')->unique();
                $pending = LeaveRequest::query()->where('employee_id', $record->id)
                    ->whereIn('status', ['pending', 'draft', 'rejected'])
                    ->where('start_date', '<=', $filters->to)->where('end_date', '>=', $filters->from)->get();
                $candidateIds = $requestIds->merge($pending->pluck('id'))->unique();
                $decisions = RequestDecision::query()->whereIn('request_id', $candidateIds)->orderBy('id')->get();
                $withdrawn = $decisions->where('decision', 'withdraw')
                    ->mapWithKeys(fn (RequestDecision $decision): array => [(int) ($decision->values['decision_id'] ?? 0) => true]);
                $cancelled = $decisions->where('decision', 'cancel')
                    ->reject(fn (RequestDecision $decision): bool => $withdrawn->has($decision->id))
                    ->pluck('request_id')->flip();
                $requests = LeaveRequest::query()->with('leaveType')->whereIn('id', $requestIds)->get()
                    ->reject(fn (LeaveRequest $request): bool => $cancelled->has($request->id)
                        || $request->status !== 'approved' || ! $request->leaveType?->covers
                        || ! $request->leaveType->is_mapped);
                foreach ($employee['leave'] as $group => $minutes) {
                    [$name, $status] = explode('|', $group, 2);
                    $related = $requests->filter(fn (LeaveRequest $request): bool => $request->status === $status
                        && (($request->leaveType?->name ?? 'unmapped')
                            .($request->leaveType?->other_name === null ? '' : ' / '.$request->leaveType->other_name)) === $name);
                    yield ['employee_code' => $record->employee_code, 'employee_name' => $record->name,
                        'date' => '', 'group' => $name, 'status' => __('messages.leave_ui.statuses.'.$status), 'target_kind' => '',
                        'request_count' => $related->count(), 'minutes' => $minutes,
                        'created' => 0, 'used' => 0, 'available' => 0, 'applied_minutes' => 0,
                        'request_numbers' => $related->pluck('external_id')->implode(', '), 'explanations' => ''];
                }
                foreach ($employee['pending_leave_requests'] as $status => $count) {
                    $pendingNumbers = $pending->filter(function (LeaveRequest $request) use ($status, $cancelled, $dayFiltered, $selectedDates): bool {
                        if ($request->status !== $status || $cancelled->has($request->id)) {
                            return false;
                        }
                        if (! $dayFiltered) {
                            return true;
                        }
                        foreach ($selectedDates->keys() as $date) {
                            if ($date >= $request->start_date->toDateString() && $date <= $request->end_date->toDateString()) {
                                return true;
                            }
                        }

                        return false;
                    })->pluck('external_id')->implode(', ');
                    yield ['employee_code' => $record->employee_code, 'employee_name' => $record->name,
                        'date' => '', 'group' => '', 'status' => __('messages.leave_ui.statuses.'.$status), 'target_kind' => '',
                        'request_count' => $count, 'minutes' => 0,
                        'created' => 0, 'used' => 0, 'available' => 0, 'applied_minutes' => 0,
                        'request_numbers' => $pendingNumbers, 'explanations' => ''];
                }
                yield ['employee_code' => $record->employee_code, 'employee_name' => $record->name,
                    'date' => '', 'group' => __('messages.export_ui.credit'), 'status' => '', 'target_kind' => '',
                    'request_count' => 0, 'minutes' => 0,
                    'created' => $employee['credits']['created'], 'used' => $employee['credits']['used_in_period'],
                    'available' => $employee['credits']['available_at_period_end'], 'applied_minutes' => 0,
                    'request_numbers' => '', 'explanations' => ''];
                foreach (Allocation::query()->with(['sourceCredit.sourceDay.request', 'targetDay'])
                    ->where('employee_id', $record->id)->whereNotNull('source_credit_id')
                    ->whereNull('superseded_by_run_id')->whereHas('sourceCredit', fn ($query) => $query->whereNull('superseded_by_run_id'))
                    ->whereHas('targetDay', function ($query) use ($filters): void {
                        $query->whereNull('superseded_by_run_id')->whereBetween('work_date', [$filters->from, $filters->to]);
                    })
                    ->lazyById(500) as $allocation) {
                    yield ['employee_code' => $record->employee_code, 'employee_name' => $record->name,
                        'date' => $allocation->targetDay->work_date->toDateString(),
                        'group' => __('messages.export_ui.credit_deduction'), 'status' => '',
                        'target_kind' => __('messages.leave_ui.target_kinds.'.$allocation->target_kind),
                        'request_count' => 0, 'minutes' => 0, 'created' => 0, 'used' => 0, 'available' => 0,
                        'applied_minutes' => (int) $allocation->minutes,
                        'request_numbers' => $allocation->sourceCredit?->sourceDay?->request?->external_id ?? '',
                        'explanations' => $this->explanations->render($allocation->explanation ?? [])];
                }

                continue;
            }
            foreach ($this->summary->perDay($filters, $record->id) as $day) {
                if ($export->kind === 'lateness_reconciliation' && $day['minutes']['late']['gross'] + $day['minutes']['early']['gross'] + $day['minutes']['exit']['gross'] === 0) {
                    continue;
                }
                if ($export->kind === 'absence' && $day['status'] !== 'absent') {
                    continue;
                }
                if ($export->kind === 'missing_punches' && $day['status'] !== 'missing_punch') {
                    continue;
                }
                $gross = $leave = $credit = $remaining = 0;
                foreach (AttendanceSummary::KINDS as $kind) {
                    $gross += $day['minutes'][$kind]['gross'];
                    $leave += $day['minutes'][$kind]['reconciled_by_leave'];
                    $credit += $day['minutes'][$kind]['reconciled_by_credit'];
                    $remaining += $day['minutes'][$kind]['remaining'];
                }
                $linked = collect($day['allocations'])->pluck('explanation')->filter();
                yield [
                    'employee_code' => $record->employee_code, 'employee_name' => $record->name,
                    'date' => $day['work_date'], 'status' => __('messages.office_ui.statuses.'.$day['status']),
                    'first_in' => $day['first_in'] ? substr($day['first_in'], 11, 5) : '',
                    'last_out' => $day['last_out'] ? substr($day['last_out'], 11, 5) : '',
                    'absence' => $day['minutes']['absence']['gross'], 'late' => $day['minutes']['late']['gross'],
                    'early' => $day['minutes']['early']['gross'], 'exit' => $day['minutes']['exit']['gross'],
                    'gross' => $gross, 'leave' => $leave, 'credit' => $credit, 'remaining' => $remaining,
                    'request_numbers' => $linked->pluck('request')->filter()->unique()->implode(', '),
                    'explanations' => $linked->map(fn (array $explanation): string => $this->explanations->render($explanation))->implode(' | '),
                    'reviewed' => $day['reviewed'],
                ];
            }
        }
    }

    public function statement(AttendanceExport $export, Company $company, ReportFilters $filters): array
    {
        $employee = Employee::query()->whereKey($filters->employeeIds[0])->firstOrFail();
        $totals = $this->summary->totals($filters);
        $days = iterator_to_array($this->rows($export, $filters), false);

        return ['company' => $company, 'employee' => $employee, 'filters' => $filters,
            'totals' => $totals, 'days' => $days, 'header' => $this->header($export, $company, $filters)];
    }

    private function meals(ReportFilters $filters): Generator
    {
        $ids = $this->summary->drillDown($filters, 'restaurant');
        $dayFiltered = $filters->branchIds !== [] || $filters->departments !== []
            || $filters->managerEmployeeIds !== [] || $filters->workPatterns !== [] || $filters->statuses !== [];
        foreach (array_chunk($ids, 100) as $employeeIds) {
            $dates = [];
            if ($dayFiltered) {
                foreach ($employeeIds as $id) {
                    foreach ($this->summary->perDay($filters, $id) as $day) {
                        $dates[$id][$day['work_date']] = true;
                    }
                }
            }
            foreach (Meal::query()->where('company_id', $filters->companyId)->whereIn('employee_id', $employeeIds)
                ->whereNull('superseded_by_run_id')->whereBetween('meal_date', [$filters->from, $filters->to])
                ->with('employee')->lazyById(500) as $meal) {
                if ($dayFiltered && ! isset($dates[$meal->employee_id][$meal->meal_date->toDateString()])) {
                    continue;
                }
                yield ['employee_code' => $meal->employee?->employee_code ?? '', 'employee_name' => $meal->employee?->name ?? '',
                    'department' => '', 'date' => $meal->meal_date->toDateString(), 'meals' => 1, 'repetitions' => (int) $meal->repeat_count];
            }
        }
        foreach ($this->summary->totals($filters)['meals']['non_employee_by_department'] as $department => $count) {
            yield ['employee_code' => '', 'employee_name' => '', 'department' => $department,
                'date' => '', 'meals' => $count, 'repetitions' => 0];
        }
    }

    private function issues(ReportFilters $filters): Generator
    {
        foreach ($this->summary->perEmployee($filters) as $row) {
            $employee = Employee::query()->whereKey($row['employee_id'])->firstOrFail();
            foreach ($row['issues']['attributable'] as $type => $count) {
                yield ['employee_code' => $employee->employee_code, 'employee_name' => $employee->name,
                    'issue_type' => __('messages.attendance_ui.issue_types.'.$type), 'issues' => $count];
            }
        }
        foreach ($this->summary->totals($filters)['issues']['company_wide'] as $type => $count) {
            yield ['employee_code' => '', 'employee_name' => '',
                'issue_type' => __('messages.attendance_ui.issue_types.'.$type), 'issues' => $count];
        }
    }
}
