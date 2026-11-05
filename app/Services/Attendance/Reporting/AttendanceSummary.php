<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\Meal;
use App\Models\Attendance\MonthSummary;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestDecision;
use App\Services\Attendance\OfficePolicy;
use App\Services\Employees\EmployeeHistory;
use App\Services\Leave\HoursSettlement;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceSummary
{
    public const KINDS = ['absence', 'late', 'early', 'exit'];

    private const SUMMARY_COLUMNS = [
        'id', 'employee_id', 'work_date', 'branch_id', 'status', 'reviewed', 'policy_id',
        'shift_start', 'shift_source', 'shift_confidence', 'holiday_ids', 'field_work_holiday_id', 'first_in', 'last_out',
        'required_leave_at', 'intervals', 'exits', 'inputs', 'elapsed_minutes', 'presence_minutes',
        'late_minutes', 'early_minutes', 'exit_minutes', 'short_minutes', 'balance_minutes',
    ];

    public function totals(ReportFilters $filters): array
    {
        if ($this->hasDayFilters($filters) || $filters->caseState !== null || $filters->caseIssueType !== null) {
            return $this->totalsLive($filters);
        }

        return $this->totalsCached($filters);
    }

    public function totalsLive(ReportFilters $filters): array
    {
        $this->assertCompany($filters);
        $totals = $this->emptySummary();
        $totals['employee_ids'] = [];
        foreach ($this->perEmployee($filters) as $row) {
            $totals['employee_ids'][] = $row['employee_id'];
            $this->merge($totals, $row);
        }
        $nonEmployeeMeals = $this->nonEmployeeMeals($filters);
        $totals['meals']['non_employee'] = array_sum($nonEmployeeMeals);
        $totals['meals']['non_employee_by_department'] = $nonEmployeeMeals;
        $totals['issues']['company_wide'] = $this->companyIssues($filters);
        $totals['leave_day_equivalents'] = array_map(fn (int $minutes): float => $minutes / 480, $totals['leave']);

        return $totals;
    }

    private function totalsCached(ReportFilters $filters): array
    {
        $this->assertCompany($filters);
        $company = Company::findOrFail($filters->companyId);
        $summaries = app(MonthSummaries::class);
        $totals = $this->emptySummary();
        $totals['employee_ids'] = [];
        $employees = Employee::query()->where('company_id', $filters->companyId);
        if ($filters->employeeIds !== []) {
            $employees->whereIn('id', $filters->employeeIds);
        }
        $today = CarbonImmutable::now('Asia/Baghdad')->toDateString();
        $allIssues = $this->attributableIssuesAll($filters);
        $months = [];
        for ($month = CarbonImmutable::parse($filters->from)->startOfMonth(); $month->toDateString() <= $filters->to; $month = $month->addMonth()) {
            $first = $month->toDateString();
            $last = $month->endOfMonth()->toDateString();
            $months[$first] = [
                'from' => max($filters->from, $first), 'to' => min($filters->to, $last),
                'whole' => $filters->from <= $first && $filters->to >= min($last, $today) && $first <= $today,
            ];
        }
        $lastId = 0;
        while (true) {
            $chunk = (clone $employees)->where('id', '>', $lastId)->orderBy('id')->limit(100)->get();
            if ($chunk->isEmpty()) {
                break;
            }
            $lastId = (int) $chunk->last()->id;
            $ids = $chunk->pluck('id')->all();
            $rows = [];
            foreach ($chunk as $employee) {
                $rows[$employee->id] = $this->emptySummary();
                $rows[$employee->id]['employee_id'] = (int) $employee->id;
                $rows[$employee->id]['active_employees'] = $employee->is_active ? 1 : 0;
                $rows[$employee->id]['without_work_pattern'] = $employee->work_pattern === null ? 1 : 0;
            }
            $stored = MonthSummary::query()->where('company_id', $filters->companyId)
                ->whereIn('employee_id', $ids)->whereIn('month', array_keys($months))->get()
                ->keyBy(fn (MonthSummary $row): string => $row->employee_id.'|'.$row->month->toDateString());
            foreach ($months as $month => $slice) {
                $liveIds = $ids;
                if ($slice['whole']) {
                    $liveIds = [];
                    $missing = [];
                    $stale = [];
                    foreach ($ids as $id) {
                        $cached = $stored->get($id.'|'.$month);
                        if ($cached !== null && ! $cached->is_dirty && $cached->attendance !== null
                            && ($month !== substr($today, 0, 8).'01'
                                || $cached->built_at?->timezone('Asia/Baghdad')->toDateString() === $today)) {
                            $this->mergeSnapshot($rows[$id], $cached->attendance);
                        } else {
                            $liveIds[] = $id;
                            if ($cached === null) {
                                $missing[] = $id;
                            } elseif (! $cached->is_dirty && $month === substr($today, 0, 8).'01') {
                                $stale[] = $id;
                            }
                        }
                    }
                    if ($missing !== []) {
                        $summaries->ensureMissing($filters->companyId, $missing, $month);
                    }
                    if ($stale !== []) {
                        $summaries->markDirty($filters->companyId, $stale, $month, $month);
                    } elseif ($liveIds !== [] && $missing === []) {
                        $summaries->queue($filters->companyId);
                    }
                }
                if ($liveIds !== []) {
                    $liveFilters = ReportFilters::fromArray($company, ['from' => $slice['from'],
                        'to' => $slice['whole'] ? min($slice['to'], $today) : $slice['to'],
                        'employee_ids' => $liveIds]);
                    foreach ($this->perEmployee($liveFilters) as $live) {
                        $this->mergeSnapshot($rows[$live['employee_id']], MonthSummaries::snapshot($live));
                    }
                }
                if ($slice['whole'] && $slice['to'] > $today) {
                    $futureFilters = ReportFilters::fromArray($company, ['from' => CarbonImmutable::parse($today)->addDay()->toDateString(),
                        'to' => $slice['to'], 'employee_ids' => $ids]);
                    foreach ($this->perEmployee($futureFilters) as $live) {
                        $this->mergeSnapshot($rows[$live['employee_id']], MonthSummaries::snapshot($live));
                    }
                }
            }
            $pending = $this->pendingRequests($filters, $ids, []);
            $credits = $this->credits($filters, $ids);
            foreach ($chunk as $employee) {
                $row = &$rows[$employee->id];
                $row['pending_leave_requests'] = $pending[$employee->id] ?? [];
                $row['credits'] = $credits[$employee->id] ?? $row['credits'];
                $row['issues']['attributable'] = $allIssues[$employee->id] ?? [];
                $totals['employee_ids'][] = (int) $employee->id;
                $this->merge($totals, $row);
                unset($row);
            }
        }
        $nonEmployeeMeals = $this->nonEmployeeMeals($filters);
        $totals['meals']['non_employee'] = array_sum($nonEmployeeMeals);
        $totals['meals']['non_employee_by_department'] = $nonEmployeeMeals;
        $totals['issues']['company_wide'] = $this->companyIssues($filters);
        $totals['leave_day_equivalents'] = array_map(fn (int $minutes): float => $minutes / 480, $totals['leave']);

        return $totals;
    }

    private function mergeSnapshot(array &$row, array $snapshot): void
    {
        $office = $row['office_employees_assessed'];
        $this->merge($row, array_replace($this->emptySummary(), $snapshot));
        $row['office_employees_assessed'] = max($office, $snapshot['office_employees_assessed']);
    }

    public function perEmployee(ReportFilters $filters, bool $includeLive = true): Generator
    {
        $this->assertCompany($filters);
        $allIssues = $includeLive ? $this->attributableIssuesAll($filters) : [];
        $employees = Employee::query()->where('company_id', $filters->companyId);
        if ($filters->employeeIds !== []) {
            $employees->whereIn('id', $filters->employeeIds);
        }
        $lastId = 0;
        while (true) {
            $chunk = (clone $employees)->where('id', '>', $lastId)->orderBy('id')->limit(100)->get();
            if ($chunk->isEmpty()) {
                break;
            }
            $lastId = (int) $chunk->last()->id;
            $ids = $chunk->pluck('id')->all();
            $days = $this->filterHistoricalAttributes(
                $this->days($filters, $ids, self::SUMMARY_COLUMNS, withPolicy: true)
                    ->orderBy('employee_id')->orderBy('work_date')->get(), $filters, $chunk,
            );
            $byEmployee = $days->groupBy('employee_id');
            $selectedDates = [];
            foreach ($days as $day) {
                $selectedDates[$day->employee_id][$day->work_date->toDateString()] = true;
            }
            $allocations = $this->allocations($filters, $days->pluck('id')->all());
            $blocked = $allocations->isEmpty() && ! $this->hasToleranceCandidates($days) ? [] : $this->blockedKinds($filters, $days);
            $leave = $this->leaveDays($filters, $ids, $selectedDates);
            $pending = $includeLive ? $this->pendingRequests($filters, $ids, $selectedDates) : [];
            $credits = $includeLive ? $this->credits($filters, $ids) : [];
            $meals = $this->meals($filters, $ids, $selectedDates);
            $nothingDays = $this->nothingToReconcileDays($filters, $ids);
            foreach ($chunk as $employee) {
                $employeeDays = $byEmployee->get($employee->id, collect());
                if ($this->hasDayFilters($filters) && $employeeDays->isEmpty()) {
                    continue;
                }
                $row = $this->emptySummary();
                $row['employee_id'] = (int) $employee->id;
                $row['employee_code'] = $employee->employee_code;
                $row['active_employees'] = $employee->is_active ? 1 : 0;
                $row['without_work_pattern'] = $employee->work_pattern === null ? 1 : 0;
                foreach ($employeeDays as $day) {
                    $this->addDay($row, $this->project($day, $allocations->get($day->id, collect()),
                        isset($nothingDays[$employee->id.'|'.$day->work_date->toDateString()]), false, $blocked), $employee->work_pattern?->value);
                }
                $row['leave'] = $leave[$employee->id] ?? [];
                $row['leave_day_equivalents'] = array_map(fn (int $minutes): float => $minutes / 480, $row['leave']);
                $row['pending_leave_requests'] = $pending[$employee->id] ?? [];
                $row['credits'] = $credits[$employee->id] ?? $row['credits'];
                $row['meals'] = $meals[$employee->id] ?? $row['meals'];
                $row['issues']['attributable'] = $allIssues[$employee->id] ?? [];
                yield $row;
            }
        }
    }

    public function selectedDays(ReportFilters $filters, array $employeeIds = [], ?array $columns = null, array $dayIds = []): Generator
    {
        $this->assertCompany($filters);
        $employees = Employee::query()->where('company_id', $filters->companyId);
        if ($filters->employeeIds !== []) {
            $employees->whereIn('id', $filters->employeeIds);
        }
        if ($employeeIds !== []) {
            $employees->whereIn('id', $employeeIds);
        }
        if ($dayIds !== [] && $employeeIds === []) {
            $employees->whereIn('id', Day::query()->where('company_id', $filters->companyId)
                ->whereIn('id', $dayIds)->select('employee_id'));
        }
        if ($filters->departments === [] && $filters->managerEmployeeIds === []) {
            $employees->select('id');
        }
        $lastId = 0;
        while (true) {
            $chunk = (clone $employees)->where('id', '>', $lastId)->orderBy('id')->limit(500)->get();
            if ($chunk->isEmpty()) {
                break;
            }
            $lastId = (int) $chunk->last()->id;
            $days = $this->filterHistoricalAttributes(
                $this->days($filters, $chunk->pluck('id')->all(), $columns, $dayIds)
                    ->orderBy('employee_id')->orderBy('work_date')->get(),
                $filters, $chunk,
            );
            foreach ($days as $day) {
                yield $day;
            }
        }
    }

    public function perDay(ReportFilters $filters, int $employeeId): Generator
    {
        $this->assertCompany($filters);
        $employee = Employee::query()->where('company_id', $filters->companyId)->whereKey($employeeId)->firstOrFail();
        abort_if($filters->employeeIds !== [] && ! in_array($employeeId, $filters->employeeIds, true), 403);
        $lastId = 0;
        $lastDate = null;
        while (true) {
            $query = $this->days($filters, [$employeeId]);
            if ($lastDate !== null) {
                $query->where(function ($nested) use ($lastDate, $lastId): void {
                    $nested->where('work_date', '>', $lastDate)
                        ->orWhere(fn ($same) => $same->where('work_date', $lastDate)->where('id', '>', $lastId));
                });
            }
            $days = $query->orderBy('work_date')->orderBy('id')->limit(100)->get();
            if ($days->isEmpty()) {
                break;
            }
            $lastId = (int) $days->last()->id;
            $lastDate = $days->last()->work_date->toDateString();
            $days = $this->filterHistoricalAttributes($days, $filters, collect([$employee]));
            if ($days->isEmpty()) {
                continue;
            }
            $allocations = $this->allocations($filters, $days->pluck('id')->all());
            $blocked = $allocations->isEmpty() && ! $this->hasToleranceCandidates($days) ? [] : $this->blockedKinds($filters, $days);
            $details = Allocation::query()->where('company_id', $filters->companyId)
                ->whereIn('target_day_id', $days->pluck('id'))->whereNull('superseded_by_run_id')
                ->orderBy('id')->get()->groupBy('target_day_id');
            $nothingDays = $this->nothingToReconcileDays($filters, [$employeeId]);
            foreach ($days as $day) {
                $row = $this->project($day, $allocations->get($day->id, collect()),
                    isset($nothingDays[$employeeId.'|'.$day->work_date->toDateString()]), true, $blocked);
                $row['allocations'] = $details->get($day->id, collect())->map(fn (Allocation $allocation): array => [
                    'id' => (int) $allocation->id, 'source_request_day_id' => $allocation->source_request_day_id,
                    'source_credit_id' => $allocation->source_credit_id, 'target_kind' => $allocation->target_kind,
                    'minutes' => (int) $allocation->minutes, 'explanation' => $allocation->explanation,
                ])->all();
                yield $row;
            }
        }
    }

    public function presentationRowsForDays(ReportFilters $filters, array $dayIds): array
    {
        if ($dayIds === []) {
            return [];
        }

        $days = collect(iterator_to_array($this->selectedDays($filters, dayIds: $dayIds)));
        $allocations = $this->allocations($filters, $days->pluck('id')->all());
        $blocked = $allocations->isEmpty() && ! $this->hasToleranceCandidates($days) ? [] : $this->blockedKinds($filters, $days);
        $nothingDays = $this->nothingToReconcileDays($filters, $days->pluck('employee_id')->unique()->all());
        $rows = [];
        foreach ($days as $day) {
            $rows[$day->id] = $this->project($day, $allocations->get($day->id, collect()),
                isset($nothingDays[$day->employee_id.'|'.$day->work_date->toDateString()]), true, $blocked);
        }

        return $rows;
    }

    public function drillDown(ReportFilters $filters, string $tile): array
    {
        return array_keys($this->drillDownRows($filters, $tile));
    }

    /**
     * Matching employees with their own figure for the tile and, for day tiles, the matching day ids.
     *
     * @return array<int, array{value: int|float|null, day_ids: list<int>}>
     */
    public function drillDownRows(ReportFilters $filters, string $tile): array
    {
        $this->assertCompany($filters);
        $dayKey = $tile === 'absent_uncovered' || in_array($tile, ReportFilters::STATUSES, true) ? $tile : null;
        $rows = [];
        foreach ($this->perEmployee($filters) as $employee) {
            if (! $this->matchesTile($employee, $tile)) {
                continue;
            }
            $dayIds = $dayKey === null ? [] : ($employee['day_ids'][$dayKey] ?? []);
            if ($tile === 'present') {
                $dayIds = array_merge($dayIds, $employee['day_ids']['field_work'] ?? []);
            }
            $rows[$employee['employee_id']] = ['value' => ReportTiles::fromTotals($employee)[$tile] ?? null, 'day_ids' => $dayIds];
        }

        return $rows;
    }

    public function drillDownDayIds(ReportFilters $filters, string $status): Generator
    {
        abort_unless(in_array($status, [...ReportFilters::STATUSES, 'absent_uncovered'], true), 404);
        foreach ($this->perEmployee($filters) as $employee) {
            $ids = $employee['day_ids'][$status] ?? [];
            if ($status === 'present') {
                $ids = array_merge($ids, $employee['day_ids']['field_work'] ?? []);
            }
            foreach ($ids as $id) {
                yield $id;
            }
        }
    }

    public function issueIds(ReportFilters $filters, int $employeeId): array
    {
        $this->assertCompany($filters);
        $employee = Employee::query()->where('company_id', $filters->companyId)->findOrFail($employeeId);
        $requestIds = LeaveRequest::query()->where('company_id', $filters->companyId)
            ->where('employee_id', $employeeId)->pluck('id');
        $keys = [$employee->employee_code, 'owner:'.$employee->employee_code];
        foreach ($requestIds as $id) {
            $keys[] = 'request:'.$id;
        }

        return DataIssue::query()->where('company_id', $filters->companyId)->where('status', 'open')
            ->where(function ($query) use ($keys, $employeeId): void {
                $query->whereIn('subject_key', $keys)
                    ->orWhere(fn ($nested) => $nested->where('subject_type', 'leave_request_day')
                        ->where('subject_key', 'like', 'employee:'.$employeeId.':request_day:%'));
            })->orderBy('id')->pluck('id')->all();
    }

    private function days(ReportFilters $filters, array $ids, ?array $columns = null, array $dayIds = [], bool $withPolicy = false): Builder
    {
        $query = Day::query()->where('company_id', $filters->companyId)->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$filters->from, $filters->to])->whereNull('superseded_by_run_id');
        if ($columns === null || $withPolicy) {
            $query->with('policy');
        }
        if ($columns !== null) {
            $query->select($columns);
        }
        if ($dayIds !== []) {
            $query->whereIn('id', $dayIds);
        }
        if ($filters->branchIds !== []) {
            $query->whereIn('branch_id', $filters->branchIds);
        }
        if ($filters->workPatterns !== []) {
            $query->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(inputs, '$.work_pattern'))"), $filters->workPatterns);
        }
        if ($filters->statuses !== []) {
            $query->whereIn('status', $filters->statuses);
        }

        return $query;
    }

    private function filterHistoricalAttributes(Collection $days, ReportFilters $filters, Collection $employees): Collection
    {
        if ($days->isEmpty() || ($filters->departments === [] && $filters->managerEmployeeIds === [])) {
            return $days;
        }
        $versions = EmployeeVersion::query()->where('company_id', $filters->companyId)
            ->whereIn('employee_id', $employees->pluck('id'))->where('effective_from', '<=', $filters->to)
            ->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
        $historyService = app(EmployeeHistory::class);
        $filtered = collect();
        $byEmployee = $days->groupBy('employee_id');
        foreach ($employees as $employee) {
            $history = $versions->get($employee->id, collect())->values();
            $values = $history->isEmpty() ? $historyService->valuesFrom($employee) : array_fill_keys(EmployeeHistory::FIELDS, null);
            $index = 0;
            foreach ($byEmployee->get($employee->id, collect())->sortBy('work_date') as $day) {
                $date = $day->work_date->toDateString();
                while ($index < $history->count() && $history[$index]->effective_from->toDateString() <= $date) {
                    foreach ($history[$index]->changes as $field => $value) {
                        $values[$field] = $value;
                    }
                    $index++;
                }
                if (($filters->departments === [] || in_array($values['department'], $filters->departments, true))
                    && ($filters->managerEmployeeIds === [] || in_array((int) $values['manager_employee_id'], $filters->managerEmployeeIds, true))) {
                    $filtered->push($day);
                }
            }
        }

        return $filtered;
    }

    private function allocations(ReportFilters $filters, array $dayIds): Collection
    {
        if ($dayIds === []) {
            return collect();
        }

        // MySQL refuses more than 65,535 placeholders, which 250 employees over a long period exceed.
        $rows = collect();
        foreach (array_chunk($dayIds, 5000) as $chunk) {
            $rows = $rows->concat(Allocation::query()->where('company_id', $filters->companyId)->whereIn('target_day_id', $chunk)
                ->whereNull('superseded_by_run_id')->selectRaw("target_day_id, target_kind, source_credit_id IS NOT NULL AS from_credit, (source_request_day_id IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(explanation, '$.code')) = 'leave_same_day') AS same_day, SUM(minutes) AS minutes, MIN(id) AS first_id")
                ->groupBy('target_day_id', 'target_kind', 'from_credit', 'same_day')->get());
        }

        return $rows->groupBy('target_day_id');
    }

    private function blockedKinds(ReportFilters $filters, Collection $days): array
    {
        if ($days->isEmpty()) {
            return [];
        }
        $decisions = RequestDecision::query()->where('company_id', $filters->companyId)
            ->whereIn('employee_id', $days->pluck('employee_id')->unique())
            ->whereBetween('work_date', [$days->min('work_date'), $days->max('work_date')])
            ->whereIn('decision', ['reverse_deduction', 'withdraw'])->orderBy('id')->get();
        $withdrawn = $decisions->where('decision', 'withdraw')
            ->map(fn (RequestDecision $decision): int => (int) ($decision->values['decision_id'] ?? 0))->all();
        $blocked = [];
        foreach ($decisions->where('decision', 'reverse_deduction') as $decision) {
            if (! in_array($decision->id, $withdrawn, true)) {
                $blocked[$decision->employee_id.'|'.$decision->work_date->toDateString().'|'.$decision->target_kind] = true;
            }
        }

        return $blocked;
    }

    private function hasToleranceCandidates(Collection $days): bool
    {
        return $days->contains(function (Day $day): bool {
            $gross = (int) $day->late_minutes + (int) $day->early_minutes
                + ($day->policy?->exits_count_toward_balance ? (int) $day->exit_minutes : 0);

            return $day->status === 'present' && (int) $day->short_minutes === 0
                && HoursSettlement::withinTolerance(0, $gross, (int) ($day->policy?->minor_gap_tolerance_minutes ?? 0));
        });
    }

    private function project(Day $day, Collection $allocations, bool $nothingToReconcile, bool $full = true, array $blocked = []): array
    {
        $gross = array_fill_keys(self::KINDS, 0);
        if ($day->status === 'absent') {
            $gross['absence'] = (int) ($day->inputs['absence_minutes'] ?? $day->policy?->absence_minutes ?? 480);
        } elseif ($day->status === 'present') {
            $gross['late'] = (int) $day->late_minutes;
            $gross['early'] = (int) $day->early_minutes;
            if ($day->policy?->exits_count_toward_balance) {
                $gross['exit'] = (int) $day->exit_minutes;
            }
        }
        $sameDayLeave = $allocations->isEmpty() ? 0 : (int) $allocations->where('same_day', 1)->sum('minutes');
        $settlesHours = $day->status === 'present' && HoursSettlement::applies((int) $day->short_minutes, $sameDayLeave);
        $uncoveredAfterSameDay = 0;
        if ($day->status === 'present') {
            foreach (['late', 'early', 'exit'] as $kind) {
                if (! isset($blocked[$day->employee_id.'|'.$day->work_date->toDateString().'|'.$kind])) {
                    $sameDayForKind = $allocations->isEmpty() ? 0 : (int) $allocations->where('target_kind', $kind)->where('same_day', 1)->sum('minutes');
                    $uncoveredAfterSameDay += max(0, $gross[$kind] - $sameDayForKind);
                }
            }
        }
        $withinTolerance = ! $settlesHours && $day->status === 'present'
            && HoursSettlement::withinTolerance((int) $day->short_minutes, $uncoveredAfterSameDay,
                (int) ($day->policy?->minor_gap_tolerance_minutes ?? 0));
        $minutes = [];
        foreach (self::KINDS as $kind) {
            // Most days have no allocations; filtering an empty collection eight times per day dominated dashboard time.
            $leave = $allocations->isEmpty() ? 0 : (int) $allocations->where('target_kind', $kind)->where('from_credit', 0)->sum('minutes');
            $credit = $allocations->isEmpty() ? 0 : (int) $allocations->where('target_kind', $kind)->where('from_credit', 1)->sum('minutes');
            $remaining = max(0, $gross[$kind] - $leave - $credit);
            $settled = ($settlesHours || $withinTolerance) && in_array($kind, ['late', 'early', 'exit'], true)
                && ! isset($blocked[$day->employee_id.'|'.$day->work_date->toDateString().'|'.$kind]) ? $remaining : 0;
            $minutes[$kind] = ['gross' => $gross[$kind], 'reconciled_by_leave' => $leave,
                'reconciled_by_credit' => $credit, 'settled_by_hours' => $settled, 'remaining' => $remaining - $settled];
        }
        $shortRemaining = max(0, (int) $day->short_minutes - array_sum(array_column($minutes, 'reconciled_by_leave'))
            - array_sum(array_column($minutes, 'reconciled_by_credit')));
        $observed = [
            'elapsed' => $day->elapsed_minutes, 'presence' => $day->presence_minutes,
            'late' => $day->late_minutes, 'early' => $day->early_minutes,
            'exit' => $day->exit_minutes, 'short' => $day->short_minutes,
            'balance' => $day->balance_minutes,
        ];
        $flags = [
            'hr_confirmed_absence' => $day->status === 'absent' && (bool) $day->reviewed,
            'absence_closed_by_request' => $day->status === 'absent' && $gross['absence'] > 0 && $minutes['absence']['remaining'] === 0,
            'short_hours' => $shortRemaining > 0,
            'within_tolerance' => $withinTolerance,
            'nothing_to_reconcile' => $nothingToReconcile,
        ];
        if (! $full) {
            // Totals read only these keys (see addDay); skipping the JSON and date casts keeps large periods fast.
            return ['day_id' => (int) $day->id, 'status' => $day->status, 'minutes' => $minutes,
                'short_remaining' => $shortRemaining,
                'inputs' => $day->inputs ?? [], 'observed' => $observed, 'flags' => $flags];
        }

        return [
            'day_id' => (int) $day->id, 'employee_id' => (int) $day->employee_id,
            'work_date' => $day->work_date->toDateString(), 'branch_id' => $day->branch_id,
            'status' => $day->status, 'reviewed' => (bool) $day->reviewed, 'minutes' => $minutes,
            'shift_start' => $day->shift_start, 'shift_source' => $day->shift_source,
            'shift_confidence' => $day->shift_confidence, 'shift_minutes' => $day->policy?->shift_minutes,
            'holiday_ids' => $day->holiday_ids ?? [],
            'field_work_holiday_id' => $day->field_work_holiday_id,
            'first_in' => $day->first_in?->format('Y-m-d H:i:s'),
            'last_out' => $day->last_out?->format('Y-m-d H:i:s'),
            'required_leave_at' => $day->required_leave_at?->format('Y-m-d H:i:s'),
            'intervals' => $day->intervals ?? [], 'exits' => $day->exits ?? [],
            'rest_minutes' => ($day->inputs['work_pattern'] ?? null) === 'office' && $day->policy
                ? OfficePolicy::restMinutes((int) $day->policy->workday_minutes, (int) $day->policy->min_presence_minutes) : null,
            'inputs' => $day->inputs ?? [], 'policy_version' => $day->policy?->version,
            'observed' => $observed,
            'short_minutes' => (int) $day->short_minutes,
            'minor_gap_tolerance' => (int) ($day->policy?->minor_gap_tolerance_minutes ?? 0),
            'short_remaining' => $shortRemaining,
            'flags' => $flags,
        ];
    }

    private function emptySummary(): array
    {
        return [
            'active_employees' => 0, 'without_work_pattern' => 0, 'assessed_working_days' => 0,
            'holiday_work_minutes' => 0, 'rest_day_work_minutes' => 0,
            'office_employees_assessed' => 0,
            'days' => array_fill_keys(ReportFilters::STATUSES, 0),
            'minutes' => array_fill_keys(self::KINDS, ['gross' => 0, 'reconciled_by_leave' => 0, 'reconciled_by_credit' => 0, 'settled_by_hours' => 0, 'remaining' => 0]),
            'observed_minutes' => array_fill_keys(['elapsed', 'presence', 'late', 'early', 'exit', 'short', 'balance'], 0),
            'short_hours_alerts' => 0, 'flags' => ['hr_confirmed_absence' => 0, 'absence_closed_by_request' => 0, 'short_hours' => 0, 'within_tolerance' => 0, 'nothing_to_reconcile' => 0],
            'leave' => [], 'leave_day_equivalents' => [], 'pending_leave_requests' => [],
            'credits' => ['created' => 0, 'used_in_period' => 0, 'available_at_period_end' => 0],
            'meals' => ['employee' => 0, 'non_employee' => 0, 'repetitions' => 0],
            'issues' => ['attributable' => [], 'company_wide' => []],
            'day_ids' => [],
        ];
    }

    private function addDay(array &$row, array $day, ?string $employeePattern): void
    {
        $row['days'][$day['status']]++;
        $row['day_ids'][$day['status']][] = $day['day_id'];
        if ($day['status'] === 'absent' && ! $day['flags']['absence_closed_by_request']) {
            $row['day_ids']['absent_uncovered'][] = $day['day_id'];
        }
        if ($day['status'] === 'holiday_worked') {
            $row['holiday_work_minutes'] += (int) $day['observed']['presence'];
        } elseif ($day['status'] === 'rest_day_worked') {
            $row['rest_day_work_minutes'] += (int) $day['observed']['presence'];
        }
        if (in_array($day['status'], ['present', 'field_work', 'absent', 'missing_punch'], true)) {
            $row['assessed_working_days']++;
            if (($day['inputs']['work_pattern'] ?? $employeePattern) === 'office') {
                $row['office_employees_assessed'] = 1;
            }
        }
        foreach (self::KINDS as $kind) {
            foreach ($day['minutes'][$kind] as $measure => $value) {
                $row['minutes'][$kind][$measure] += $value;
            }
        }
        foreach ($day['observed'] as $name => $value) {
            $row['observed_minutes'][$name] += (int) $value;
        }
        foreach ($day['flags'] as $flag => $value) {
            $row['flags'][$flag] += (int) $value;
        }
        $row['short_hours_alerts'] += $day['flags']['short_hours'] ? 1 : 0;
    }

    private function merge(array &$totals, array $row): void
    {
        $totals['office_employees_assessed'] += $row['office_employees_assessed'];
        foreach (['active_employees', 'without_work_pattern', 'assessed_working_days', 'short_hours_alerts',
            'holiday_work_minutes', 'rest_day_work_minutes'] as $key) {
            $totals[$key] += $row[$key];
        }
        foreach (['days', 'flags', 'credits', 'meals'] as $key) {
            foreach ($row[$key] as $name => $value) {
                $totals[$key][$name] = ($totals[$key][$name] ?? 0) + $value;
            }
        }
        foreach (self::KINDS as $kind) {
            foreach ($row['minutes'][$kind] as $measure => $value) {
                $totals['minutes'][$kind][$measure] += $value;
            }
        }
        foreach ($row['observed_minutes'] as $name => $value) {
            $totals['observed_minutes'][$name] += $value;
        }
        foreach (['leave', 'pending_leave_requests'] as $key) {
            foreach ($row[$key] as $group => $value) {
                $totals[$key][$group] = ($totals[$key][$group] ?? 0) + $value;
            }
        }
        foreach ($row['issues']['attributable'] as $type => $value) {
            $totals['issues']['attributable'][$type] = ($totals['issues']['attributable'][$type] ?? 0) + $value;
        }
    }

    private function leaveDays(ReportFilters $filters, array $ids, array $selectedDates): array
    {
        $result = [];
        RequestDay::query()->with('request.leaveType')->where('company_id', $filters->companyId)
            ->whereIn('employee_id', $ids)->whereNull('superseded_by_run_id')
            ->whereBetween('work_date', [$filters->from, $filters->to])->orderBy('id')->chunkById(500, function (Collection $days) use ($filters, $selectedDates, &$result): void {
                $cancelled = $this->cancelledRequests($filters->companyId, $days->pluck('request_id')->unique()->all());
                foreach ($days as $day) {
                    if ($this->hasDayFilters($filters) && ! isset($selectedDates[$day->employee_id][$day->work_date->toDateString()])) {
                        continue;
                    }
                    $request = $day->request;
                    if ($request->status !== 'approved' || ! $request->leaveType?->covers
                        || ! $request->leaveType->is_mapped || $cancelled->has($request->id)) {
                        continue;
                    }
                    $type = $request->leaveType;
                    $key = ($type?->name ?? 'unmapped').($type?->other_name === null ? '' : ' / '.$type->other_name).'|'.$request->status;
                    $result[$day->employee_id][$key] = ($result[$day->employee_id][$key] ?? 0) + (int) $day->minutes;
                }
            });

        return $result;
    }

    private function pendingRequests(ReportFilters $filters, array $ids, array $selectedDates): array
    {
        $result = [];
        $requests = LeaveRequest::query()->where('company_id', $filters->companyId)->whereIn('employee_id', $ids)
            ->whereIn('status', ['pending', 'draft', 'rejected'])->where('start_date', '<=', $filters->to)
            ->where('end_date', '>=', $filters->from)->get();
        $cancelled = $this->cancelledRequests($filters->companyId, $requests->pluck('id')->all());
        foreach ($requests as $request) {
            if ($cancelled->has($request->id)) {
                continue;
            }
            if ($this->hasDayFilters($filters)) {
                $visible = false;
                foreach (array_keys($selectedDates[$request->employee_id] ?? []) as $date) {
                    if ($date >= $request->start_date->toDateString() && $date <= $request->end_date->toDateString()) {
                        $visible = true;
                        break;
                    }
                }
                if (! $visible) {
                    continue;
                }
            }
            $result[$request->employee_id][$request->status] = ($result[$request->employee_id][$request->status] ?? 0) + 1;
        }

        return $result;
    }

    private function cancelledRequests(int $companyId, array $requestIds): Collection
    {
        if ($requestIds === []) {
            return collect();
        }
        $decisions = RequestDecision::query()->where('company_id', $companyId)
            ->whereIn('request_id', $requestIds)->orderBy('id')->get();
        $withdrawn = $decisions->where('decision', 'withdraw')
            ->mapWithKeys(fn (RequestDecision $decision): array => [(int) ($decision->values['decision_id'] ?? 0) => true]);

        return $decisions->where('decision', 'cancel')
            ->reject(fn (RequestDecision $decision): bool => $withdrawn->has($decision->id))->pluck('request_id')->flip();
    }

    private function credits(ReportFilters $filters, array $ids): array
    {
        $result = [];
        $credits = Credit::query()->with('sourceDay')->where('company_id', $filters->companyId)
            ->whereIn('employee_id', $ids)->whereNull('superseded_by_run_id')->get();
        $creditIds = $credits->pluck('id')->all();
        $spent = $creditIds === [] ? collect() : Allocation::query()->join('attendance_days as target', function ($join): void {
            $join->on('target.id', '=', 'leave_allocations.target_day_id')->on('target.company_id', '=', 'leave_allocations.company_id');
        })->where('leave_allocations.company_id', $filters->companyId)->whereIn('leave_allocations.source_credit_id', $creditIds)
            ->whereNull('leave_allocations.superseded_by_run_id')->whereNull('target.superseded_by_run_id')
            ->where('target.work_date', '<=', $filters->to)
            ->selectRaw('source_credit_id, SUM(minutes) AS total, SUM(CASE WHEN target.work_date >= ? THEN minutes ELSE 0 END) AS in_period', [$filters->from])
            ->groupBy('source_credit_id')->get()->keyBy('source_credit_id');
        foreach ($credits as $credit) {
            $sourceDate = $credit->sourceDay->work_date->toDateString();
            $row = &$result[$credit->employee_id];
            $row ??= ['created' => 0, 'used_in_period' => 0, 'available_at_period_end' => 0];
            $used = (int) ($spent[$credit->id]->total ?? 0);
            $row['used_in_period'] += (int) ($spent[$credit->id]->in_period ?? 0);
            if ($sourceDate > $filters->to) {
                unset($row);

                continue;
            }
            if ($sourceDate >= $filters->from) {
                $row['created'] += (int) $credit->minutes;
            }
            if ($credit->expires_on === null || $credit->expires_on->toDateString() >= $filters->to) {
                $row['available_at_period_end'] += max(0, (int) $credit->minutes - $used);
            }
            unset($row);
        }

        return $result;
    }

    private function meals(ReportFilters $filters, array $ids, array $selectedDates): array
    {
        $result = [];
        if ($this->hasDayFilters($filters)) {
            Meal::query()->where('company_id', $filters->companyId)->whereIn('employee_id', $ids)
                ->whereNull('superseded_by_run_id')->whereBetween('meal_date', [$filters->from, $filters->to])
                ->orderBy('id')->chunkById(500, function (Collection $meals) use (&$result, $selectedDates): void {
                    foreach ($meals as $meal) {
                        if (! isset($selectedDates[$meal->employee_id][$meal->meal_date->toDateString()])) {
                            continue;
                        }
                        $result[$meal->employee_id] ??= ['employee' => 0, 'non_employee' => 0, 'repetitions' => 0];
                        $result[$meal->employee_id]['employee']++;
                        $result[$meal->employee_id]['repetitions'] += (int) $meal->repeat_count;
                    }
                });

            return $result;
        }
        Meal::query()->where('company_id', $filters->companyId)->whereIn('employee_id', $ids)
            ->whereNull('superseded_by_run_id')->whereBetween('meal_date', [$filters->from, $filters->to])
            ->selectRaw('employee_id, COUNT(*) AS meals, SUM(repeat_count) AS repetitions')
            ->groupBy('employee_id')->get()->each(function ($row) use (&$result): void {
                $result[$row->employee_id] = ['employee' => (int) $row->meals, 'non_employee' => 0, 'repetitions' => (int) $row->repetitions];
            });

        return $result;
    }

    private function nonEmployeeMeals(ReportFilters $filters): array
    {
        if ($filters->employeeIds !== [] || $filters->departments !== [] || $filters->managerEmployeeIds !== []
            || $filters->workPatterns !== [] || $filters->statuses !== []) {
            return [];
        }
        $query = Meal::query()->where('company_id', $filters->companyId)->whereNull('employee_id')
            ->whereNull('superseded_by_run_id')->whereBetween('meal_date', [$filters->from, $filters->to]);
        if ($filters->branchIds !== []) {
            $query->whereIn('first_transaction_id', DB::table('attendance_transactions as tx')
                ->join('attendance_device_settings as setting', function ($join): void {
                    $join->on('setting.device_id', '=', 'tx.device_id')->on('setting.company_id', '=', 'tx.company_id');
                })->where('tx.company_id', $filters->companyId)->whereIn('setting.branch_id', $filters->branchIds)
                ->whereRaw('setting.effective_from <= DATE(tx.punched_at)')
                ->whereRaw('setting.id = (SELECT s.id FROM attendance_device_settings s WHERE s.company_id = setting.company_id AND s.device_id = setting.device_id AND s.effective_from <= DATE(tx.punched_at) ORDER BY s.effective_from DESC, s.id DESC LIMIT 1)')
                ->select('tx.id'));
        }

        return $query->selectRaw('department_label, COUNT(*) AS meals')->groupBy('department_label')->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->department_label => (int) $row->meals])->all();
    }

    private function attributableIssuesAll(ReportFilters $filters): array
    {
        $result = [];
        $codes = Employee::query()->where('company_id', $filters->companyId)
            ->when($filters->employeeIds !== [], fn ($query) => $query->whereIn('id', $filters->employeeIds))
            ->pluck('id', 'employee_code');
        $employeeIds = $codes->values()->flip();
        $requestIds = LeaveRequest::query()->where('company_id', $filters->companyId)
            ->when($filters->employeeIds !== [], fn ($query) => $query->whereIn('employee_id', $filters->employeeIds))
            ->pluck('employee_id', 'id');
        $issues = DataIssue::query()->where('company_id', $filters->companyId)->where('status', 'open');
        if ($filters->employeeIds !== []) {
            $keys = array_merge($codes->keys()->all(), $codes->keys()->map(fn ($code): string => 'owner:'.$code)->all(),
                $requestIds->keys()->map(fn ($id): string => 'request:'.$id)->all());
            $issues->where(function ($query) use ($keys, $filters): void {
                $query->whereIn('subject_key', $keys)->orWhere(function ($nested) use ($filters): void {
                    $nested->where('subject_type', 'leave_request_day')->where(function ($prefixes) use ($filters): void {
                        foreach ($filters->employeeIds as $id) {
                            $prefixes->orWhere('subject_key', 'like', 'employee:'.$id.':request_day:%');
                        }
                    });
                });
            });
        }
        $issues->select(['id', 'subject_type', 'subject_key', 'type'])->cursor()
            ->each(function (DataIssue $issue) use (&$result, $codes, $requestIds, $employeeIds): void {
                $key = $issue->subject_key;
                $id = $codes[$key] ?? (str_starts_with($key, 'owner:') ? $codes[substr($key, 6)] ?? null : null);
                if ($id === null && str_starts_with($key, 'request:')) {
                    $id = $requestIds[(int) substr($key, 8)] ?? null;
                }
                if ($id === null && $issue->subject_type === 'leave_request_day'
                    && preg_match('/^employee:(\d+):request_day:/', $key, $matches)) {
                    $id = (int) $matches[1];
                }
                if ($id !== null && $employeeIds->has((int) $id)) {
                    $result[$id][$issue->type] = ($result[$id][$issue->type] ?? 0) + 1;
                }
            });

        return $result;
    }

    private function nothingToReconcileDays(ReportFilters $filters, array $ids): array
    {
        $result = [];
        if ($ids === []) {
            return $result;
        }
        DataIssue::query()->where('company_id', $filters->companyId)->where('status', 'open')
            ->where('type', 'leave_with_attendance_nothing_to_reconcile')
            ->where(function ($query) use ($ids): void {
                foreach ($ids as $id) {
                    $query->orWhere('subject_key', 'like', 'employee:'.$id.':request_day:%');
                }
            })->pluck('subject_key')->each(function (string $key) use (&$result): void {
                if (preg_match('/^employee:(\d+):request_day:\d+\|(\d{4}-\d{2}-\d{2})$/', $key, $matches)) {
                    $result[$matches[1].'|'.$matches[2]] = true;
                }
            });

        return $result;
    }

    private function companyIssues(ReportFilters $filters): array
    {
        $result = [];
        $codes = Employee::query()->where('company_id', $filters->companyId)->pluck('employee_code')->flip();
        DataIssue::query()->where('company_id', $filters->companyId)->where('status', 'open')
            ->orderBy('id')->chunkById(500, function (Collection $issues) use ($filters, $codes, &$result): void {
                $requestIds = $issues->filter(fn (DataIssue $issue): bool => $issue->subject_type === 'leave_request')
                    ->map(fn (DataIssue $issue): int => (int) substr($issue->subject_key, 8))->all();
                $requestEmployees = $requestIds === [] ? collect() : LeaveRequest::query()
                    ->where('company_id', $filters->companyId)->whereIn('id', $requestIds)->pluck('employee_id', 'id');
                foreach ($issues as $issue) {
                    if ($issue->subject_type === 'leave_request_day') {
                        continue;
                    }
                    if ($issue->subject_type === 'leave_request' && $requestEmployees->get((int) substr($issue->subject_key, 8)) !== null) {
                        continue;
                    }
                    if ($issue->subject_type === 'person' && $codes->has($issue->subject_key)) {
                        continue;
                    }
                    if ($issue->subject_type === 'employee_code' && $codes->has(substr($issue->subject_key, 6))) {
                        continue;
                    }
                    if ($issue->subject_type === 'branch_date') {
                        if (! preg_match('/^branch:(\d+):(\d{4}-\d{2}-\d{2})$/', $issue->subject_key, $matches)
                            || ($filters->branchIds !== [] && ! in_array((int) $matches[1], $filters->branchIds, true))
                            || $matches[2] < $filters->from || $matches[2] > $filters->to) {
                            continue;
                        }
                    }
                    $result[$issue->type] = ($result[$issue->type] ?? 0) + 1;
                }
            });

        return $result;
    }

    private function matchesTile(array $row, string $tile): bool
    {
        if ($tile === 'absent_uncovered') {
            return count($row['day_ids']['absent_uncovered'] ?? []) > 0;
        }
        if ($tile === 'present') {
            return $row['days']['present'] + $row['days']['field_work'] > 0;
        }
        if (str_starts_with($tile, 'leave_status:')) {
            return ($row['pending_leave_requests'][substr($tile, 13)] ?? 0) > 0;
        }
        if (str_starts_with($tile, 'leave:')) {
            return ($row['leave'][substr($tile, 6)] ?? 0) > 0;
        }
        if (str_starts_with($tile, 'issue_type:')) {
            return ($row['issues']['attributable'][substr($tile, 11)] ?? 0) > 0;
        }
        if (isset($row['days'][$tile])) {
            return $row['days'][$tile] > 0;
        }
        if (isset($row['flags'][$tile])) {
            return $row['flags'][$tile] > 0;
        }

        return match ($tile) {
            'active_employees', 'without_work_pattern', 'assessed_working_days', 'short_hours_alerts' => $row[$tile] > 0,
            'office_employees_assessed' => $row['office_employees_assessed'] > 0,
            'holiday_work_minutes', 'rest_day_work_minutes' => $row[$tile] > 0,
            'late', 'early', 'absence', 'exit' => $row['minutes'][$tile]['gross'] > 0,
            'balance', 'reconciled', 'remaining' => $this->hasMinutes($row, match ($tile) {
                'balance' => 'gross', 'reconciled' => 'reconciled_by_leave', 'remaining' => 'remaining',
            }) || ($tile === 'reconciled' && $this->hasMinutes($row, 'reconciled_by_credit')),
            'credit', 'credit_available' => $row['credits']['available_at_period_end'] > 0,
            'credit_created' => $row['credits']['created'] > 0,
            'credit_used' => $row['credits']['used_in_period'] > 0,
            'meal', 'restaurant' => $row['meals']['employee'] > 0,
            'issue', 'data_issues' => array_sum($row['issues']['attributable']) > 0,
            default => false,
        };
    }

    private function hasMinutes(array $row, string $measure): bool
    {
        foreach (self::KINDS as $kind) {
            if ($row['minutes'][$kind][$measure] > 0) {
                return true;
            }
        }

        return false;
    }

    private function hasDayFilters(ReportFilters $filters): bool
    {
        return $filters->branchIds !== [] || $filters->departments !== [] || $filters->managerEmployeeIds !== []
            || $filters->workPatterns !== [] || $filters->statuses !== [];
    }

    private function assertCompany(ReportFilters $filters): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $filters->companyId, 403);
    }
}
