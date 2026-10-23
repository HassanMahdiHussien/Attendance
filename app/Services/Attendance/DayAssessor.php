<?php

namespace App\Services\Attendance;

use App\Events\DaysAssessed;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Policy;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Punch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\Leave\RequestDay;
use App\Services\AuditLogger;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class DayAssessor
{
    public function __construct(
        private OfficeDayCalculator $calculator,
        private CoverageWindows $coverage,
        private OutageDetector $outages,
        private AuditLogger $audit,
        private EmployeeHistory $history,
        private OfficePolicy $policy,
        private ShiftDayCalculator $shiftCalculator,
        private ShiftPunchOwnership $ownership,
    ) {}

    public function run(Company $company, array $scope, string $trigger): ProcessingRun
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        // A company created outside onboarding must not be skipped silently for lack of a policy.
        $this->policy->createDefault($company);
        $from = substr((string) ($scope['from'] ?? ''), 0, 10);
        $to = min(substr((string) ($scope['to'] ?? ''), 0, 10), today('Asia/Baghdad')->toDateString());
        if ($from === '' || $to === '' || $from > $to) {
            throw new InvalidArgumentException('Invalid attendance assessment scope.');
        }
        $requestedFrom = $from;
        $requestedTo = $to;
        $from = CarbonImmutable::parse($from)->subDay()->toDateString();
        $to = min(CarbonImmutable::parse($to)->addDay()->toDateString(), today('Asia/Baghdad')->toDateString());
        $scope = array_intersect_key($scope, array_flip(['employee_ids', 'branch_ids'])) + ['from' => $requestedFrom, 'to' => $requestedTo];
        foreach (['employee_ids', 'branch_ids'] as $key) {
            if (isset($scope[$key])) {
                $scope[$key] = array_values(array_unique(array_map('intval', $scope[$key])));
            }
        }
        $run = ProcessingRun::create([
            'company_id' => $company->id, 'kind' => 'days', 'trigger_source' => $trigger,
            'person_codes' => [], 'scope' => $scope, 'scope_from' => $requestedFrom.' 00:00:00',
            'scope_to' => $requestedTo.' 23:59:59', 'policy_version' => 1,
            'status' => 'processing', 'started_at' => now(),
        ]);

        try {
            $count = 0;
            $changedEmployees = [];
            $changedDates = [];
            $start = CarbonImmutable::parse($from);
            $end = CarbonImmutable::parse($to);
            for ($sliceStart = $start; $sliceStart <= $end; $sliceStart = $sliceStart->addDays(31)) {
                $sliceEnd = $sliceStart->addDays(30)->min($end);
                $sliceFrom = $sliceStart->toDateString();
                $sliceTo = $sliceEnd->toDateString();
                $dates = [];
                for ($day = $sliceStart; $day <= $sliceEnd; $day = $day->addDay()) {
                    $dates[] = $day->toDateString();
                }
                $policies = Policy::query()->where('company_id', $company->id)
                    ->whereDate('effective_from', '<=', $sliceTo)
                    ->orderByDesc('effective_from')->orderByDesc('version')->get();
                if ($policies->isEmpty()) {
                    continue;
                }
                $policyByDate = [];
                foreach ($dates as $date) {
                    $policyByDate[$date] = $policies->first(fn (Policy $policy): bool => $policy->effective_from->toDateString() <= $date)?->toArray();
                }
                $this->coverage->load($company, $sliceFrom, $sliceEnd->addDays(3)->toDateString());
                $this->outages->prepare($company, $sliceEnd->addDay()->toDateString());
                $outageCache = [];
                $employees = Employee::query()->where('company_id', $company->id);
                if (isset($scope['employee_ids'])) {
                    $employees->whereIn('id', $scope['employee_ids']);
                }
                $employees->orderBy('id')->chunkById(100, function ($chunk) use (
                    $company, $scope, $dates, $sliceFrom, $sliceTo, $policyByDate, $run, &$count, &$outageCache, &$changedEmployees, &$changedDates,
                ): void {
                    $count += DB::transaction(function () use (
                        $company, $chunk, $scope, $dates, $sliceFrom, $sliceTo, $policyByDate, $run, &$outageCache, &$changedEmployees, &$changedDates,
                    ): int {
                        return $this->assessChunk(
                            $company, $chunk->pluck('id')->all(), $scope, $dates, $sliceFrom, $sliceTo,
                            $policyByDate, $run, $outageCache, $changedEmployees, $changedDates,
                        );
                    });
                });
            }
            $run->update(['status' => 'completed', 'counts' => ['days_changed' => $count], 'finished_at' => now()]);
            $this->audit->log('attendance.days.assessed', $run, ['scope' => $scope, 'days_changed' => $count]);
            if ($changedEmployees !== []) {
                $dates = array_keys($changedDates);
                sort($dates);
                event(new DaysAssessed($company->id, array_keys($changedEmployees), $dates[0], end($dates)));
            }
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'finished_at' => now()]);
            $this->audit->log('attendance.days.failed', $run, ['scope' => $scope]);

            throw $exception;
        }

        return $run->fresh();
    }

    private function assessChunk(Company $company, array $ids, array $scope, array $dates, string $from, string $to, array $policies, ProcessingRun $run, array &$outageCache, array &$changedEmployees, array &$changedDates): int
    {
        $employees = Employee::query()->where('company_id', $company->id)->whereIn('id', $ids)
            ->orderBy('id')->lockForUpdate()->get();
        $ids = $employees->pluck('id')->all();
        $codes = $employees->pluck('employee_code')->all();
        $versions = EmployeeVersion::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereDate('effective_from', '<=', $to)->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
        $punchFrom = CarbonImmutable::parse($from)->subDay()->toDateString().' 00:00:00';
        $punchTo = CarbonImmutable::parse($to)->addDay()->toDateString().' 02:00:00';
        $punches = Punch::query()->where('company_id', $company->id)->whereIn('person_code', $codes)
            ->whereNull('superseded_by_run_id')->where('status', 'used')
            ->whereBetween('punched_at', [$punchFrom, $punchTo])->orderBy('punched_at')->orderBy('id')->get()
            ->groupBy('person_code');
        $manual = ManualPunch::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereBetween('punched_at', [$punchFrom, $punchTo])->orderBy('punched_at')->orderBy('id')->get()
            ->groupBy('employee_id');
        $decisions = DayDecision::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$from, $to])->get()
            ->groupBy(fn (DayDecision $decision): string => $decision->employee_id.'|'.$decision->work_date->toDateString());
        $leave = RequestDay::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereNull('superseded_by_run_id')->whereBetween('work_date', [$from, $to])->get()
            ->groupBy(fn (RequestDay $day): string => $day->employee_id.'|'.$day->work_date->toDateString());
        $current = Day::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$from, $to])->whereNull('superseded_by_run_id')->get()
            ->keyBy(fn (Day $day): string => $day->employee_id.'|'.$day->work_date->toDateString());
        $now = now('Asia/Baghdad');
        $changed = 0;
        foreach ($employees as $employee) {
            $deviceRows = $punches->get($employee->employee_code, collect())->map(fn (Punch $punch): array => [
                'id' => $punch->id, 'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                'role' => $punch->role, 'status' => $punch->status,
            ])->all();
            $manualRows = $manual->get($employee->id, collect())->map(fn (ManualPunch $punch): array => [
                'id' => $punch->id, 'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                'role' => $punch->role, 'reverses_id' => $punch->reverses_id,
            ])->all();
            $ownedDevice = [];
            $ownedManual = [];
            $ownedRows = $this->ownership->assign(array_merge(
                array_map(fn (array $punch): array => $punch + ['manual' => false], $deviceRows),
                array_map(fn (array $punch): array => $punch + ['manual' => true], $manualRows),
            ));
            foreach ($ownedRows as $ownedDate => $rows) {
                foreach ($rows as $row) {
                    $manualRow = $row['manual'];
                    unset($row['manual']);
                    if ($manualRow) {
                        $ownedManual[$ownedDate][] = $row;
                    } else {
                        $ownedDevice[$ownedDate][] = $row;
                    }
                }
            }
            $history = $versions->get($employee->id, collect())->values();
            $values = $history->isEmpty() ? $this->history->valuesFrom($employee) : array_fill_keys(EmployeeHistory::FIELDS, null);
            $versionIndex = 0;
            foreach ($dates as $date) {
                while ($versionIndex < $history->count() && $history[$versionIndex]->effective_from->toDateString() <= $date) {
                    foreach ($history[$versionIndex]->changes as $field => $value) {
                        $values[$field] = $value;
                    }
                    $versionIndex++;
                }
                $key = $employee->id.'|'.$date;
                $old = $current->get($key);
                // Neighbour dates can change when a shift punch crosses midnight, but an office day needs its own requested scope.
                if (($date < $scope['from'] || $date > $scope['to'])
                    && (! in_array($values['work_pattern'], ['shift', 'security'], true)
                        || ($old === null && ($ownedDevice[$date] ?? []) === [] && ($ownedManual[$date] ?? []) === []))) {
                    continue;
                }
                $branchId = $values['branch_id'] === null ? null : (int) $values['branch_id'];
                if ((isset($scope['branch_ids']) && ! in_array($branchId, $scope['branch_ids'], true))
                    || ($values['hire_date'] !== null && substr((string) $values['hire_date'], 0, 10) > $date)
                    || ($employee->exit_status->value === 'confirmed' && $employee->last_working_day !== null
                        && $employee->last_working_day->toDateString() < $date)
                    || $policies[$date] === null) {
                    if ($old !== null) {
                        $old->update(['superseded_by_run_id' => $run->id]);
                        $changed++;
                        $changedEmployees[$employee->id] = true;
                        $changedDates[$date] = true;
                    }

                    continue;
                }
                $input = $values + [
                    'exit_status' => $employee->exit_status->value,
                    'last_working_day' => $employee->last_working_day?->toDateString(),
                ];
                $isShift = in_array($values['work_pattern'], ['shift', 'security'], true);
                $dailyPunches = $isShift ? ($ownedDevice[$date] ?? []) : array_values(array_filter($deviceRows,
                    fn (array $punch): bool => substr($punch['punched_at'], 0, 10) === $date));
                $dailyManual = $isShift ? ($ownedManual[$date] ?? []) : array_values(array_filter($manualRows,
                    fn (array $punch): bool => substr($punch['punched_at'], 0, 10) === $date));
                $dailyDecisions = $decisions->get($key, collect())->map(fn (DayDecision $decision): array => [
                    'id' => $decision->id, 'decision' => $decision->decision,
                    'shift_start' => $decision->shift_start,
                    'created_at' => $decision->created_at->format('Y-m-d H:i:s'),
                ])->all();
                $calculator = $isShift ? $this->shiftCalculator : $this->calculator;
                $leaveMinutes = (int) $leave->get($key, collect())->sum('minutes');
                $calculate = fn (bool $covered, bool $outage): array => $isShift
                    ? $calculator->calculate($input, $policies[$date], $dailyPunches, $dailyManual, $dailyDecisions, $covered, $outage, $date, $now, $leaveMinutes)
                    : $calculator->calculate($input, $policies[$date], $dailyPunches, $dailyManual, $dailyDecisions, $covered, $outage, $date, $now);
                $candidate = $calculate(false, false);
                $required = $candidate['required_leave_at'];
                $until = $required === null ? null : CarbonImmutable::parse($required)->addMinutes((int) $policies[$date]['close_grace_minutes'])->format('Y-m-d H:i:s');
                $windowStart = $isShift && ($candidate['shift_start'] ?? null) !== null
                    ? $date.' '.$candidate['shift_start'] : $date.' 00:00:00';
                $covered = $until !== null && ($isShift
                    ? $this->coverage->coversWindow($branchId, $windowStart, $until)
                    : $this->coverage->covers($branchId, $date, $until));
                if (($date < $scope['from'] || $date > $scope['to']) && $old === null && ! $covered) {
                    continue;
                }
                $outage = false;
                if ($covered && $now >= CarbonImmutable::parse($until) && $candidate['status'] !== 'rest_day'
                    && $candidate['status'] !== 'pattern_missing' && $branchId !== null) {
                    $outageKey = $branchId.'|'.$date.($isShift ? '|'.$windowStart.'|'.$until : '');
                    $outageCache[$outageKey] ??= $isShift
                        ? $this->outages->run($company, $branchId, $date, $until, true, true, $windowStart)
                        : $this->outages->run($company, $branchId, $date, $date.' 23:59:59', true, true);
                    $outage = $outageCache[$outageKey];
                }
                $result = $calculate($covered, $outage);
                $differences = $this->differences($old, $result);
                if ($differences === []) {
                    continue;
                }
                if ($old !== null) {
                    $old->update(['superseded_by_run_id' => $run->id]);
                }
                Day::create($result + [
                    'company_id' => $company->id, 'employee_id' => $employee->id,
                    'run_id' => $run->id, 'changes' => $differences,
                ]);
                $changed++;
                $changedEmployees[$employee->id] = true;
                $changedDates[$date] = true;
            }
        }

        return $changed;
    }

    private function differences(?Day $old, array $result): array
    {
        $changes = [];
        foreach ($result as $field => $after) {
            $before = $old?->{$field};
            if ($before instanceof DateTimeInterface) {
                $before = $before->format($field === 'work_date' ? 'Y-m-d' : 'Y-m-d H:i:s');
            }
            if ($before != $after) {
                $changes[$field] = ['before' => $before, 'after' => $after];
            }
        }

        return $changes;
    }
}
