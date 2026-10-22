<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Policy;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Punch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
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
        $scope = array_intersect_key($scope, array_flip(['employee_ids', 'branch_ids'])) + ['from' => $from, 'to' => $to];
        foreach (['employee_ids', 'branch_ids'] as $key) {
            if (isset($scope[$key])) {
                $scope[$key] = array_values(array_unique(array_map('intval', $scope[$key])));
            }
        }
        $run = ProcessingRun::create([
            'company_id' => $company->id, 'kind' => 'days', 'trigger_source' => $trigger,
            'person_codes' => [], 'scope' => $scope, 'scope_from' => $from.' 00:00:00',
            'scope_to' => $to.' 23:59:59', 'policy_version' => 1,
            'status' => 'processing', 'started_at' => now(),
        ]);

        try {
            $count = 0;
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
                $this->outages->prepare($company, $sliceTo);
                $outageCache = [];
                $employees = Employee::query()->where('company_id', $company->id);
                if (isset($scope['employee_ids'])) {
                    $employees->whereIn('id', $scope['employee_ids']);
                }
                $employees->orderBy('id')->chunkById(100, function ($chunk) use (
                    $company, $scope, $dates, $sliceFrom, $sliceTo, $policyByDate, $run, &$count, &$outageCache,
                ): void {
                    $count += DB::transaction(fn (): int => $this->assessChunk(
                        $company, $chunk->pluck('id')->all(), $scope, $dates, $sliceFrom, $sliceTo,
                        $policyByDate, $run, $outageCache,
                    ));
                });
            }
            $run->update(['status' => 'completed', 'counts' => ['days_changed' => $count], 'finished_at' => now()]);
            $this->audit->log('attendance.days.assessed', $run, ['scope' => $scope, 'days_changed' => $count]);
        } catch (Throwable $exception) {
            $run->update(['status' => 'failed', 'finished_at' => now()]);
            $this->audit->log('attendance.days.failed', $run, ['scope' => $scope]);

            throw $exception;
        }

        return $run->fresh();
    }

    private function assessChunk(Company $company, array $ids, array $scope, array $dates, string $from, string $to, array $policies, ProcessingRun $run, array &$outageCache): int
    {
        $employees = Employee::query()->where('company_id', $company->id)->whereIn('id', $ids)
            ->orderBy('id')->lockForUpdate()->get();
        $ids = $employees->pluck('id')->all();
        $codes = $employees->pluck('employee_code')->all();
        $versions = EmployeeVersion::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereDate('effective_from', '<=', $to)->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
        $punches = Punch::query()->where('company_id', $company->id)->whereIn('person_code', $codes)
            ->whereNull('superseded_by_run_id')->where('status', 'used')
            ->whereBetween('punched_at', [$from.' 00:00:00', $to.' 23:59:59'])->get()
            ->groupBy(fn (Punch $punch): string => $punch->person_code.'|'.$punch->punched_at->toDateString());
        $manual = ManualPunch::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereBetween('punched_at', [$from.' 00:00:00', $to.' 23:59:59'])->get()
            ->groupBy(fn (ManualPunch $punch): string => $punch->employee_id.'|'.$punch->punched_at->toDateString());
        $decisions = DayDecision::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$from, $to])->get()
            ->groupBy(fn (DayDecision $decision): string => $decision->employee_id.'|'.$decision->work_date->toDateString());
        $current = Day::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$from, $to])->whereNull('superseded_by_run_id')->get()
            ->keyBy(fn (Day $day): string => $day->employee_id.'|'.$day->work_date->toDateString());
        $now = now('Asia/Baghdad');
        $changed = 0;
        foreach ($employees as $employee) {
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
                $branchId = $values['branch_id'] === null ? null : (int) $values['branch_id'];
                if ((isset($scope['branch_ids']) && ! in_array($branchId, $scope['branch_ids'], true))
                    || ($values['work_pattern'] !== null && $values['work_pattern'] !== 'office')
                    || ($values['hire_date'] !== null && substr((string) $values['hire_date'], 0, 10) > $date)
                    || ($employee->exit_status->value === 'confirmed' && $employee->last_working_day !== null
                        && $employee->last_working_day->toDateString() < $date)
                    || $policies[$date] === null) {
                    if ($old !== null) {
                        $old->update(['superseded_by_run_id' => $run->id]);
                        $changed++;
                    }

                    continue;
                }
                $input = $values + [
                    'exit_status' => $employee->exit_status->value,
                    'last_working_day' => $employee->last_working_day?->toDateString(),
                ];
                $dailyPunches = $punches->get($employee->employee_code.'|'.$date, collect())
                    ->map(fn (Punch $punch): array => [
                        'id' => $punch->id, 'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                        'role' => $punch->role, 'status' => $punch->status,
                    ])->all();
                $dailyManual = $manual->get($key, collect())->map(fn (ManualPunch $punch): array => [
                    'id' => $punch->id, 'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                    'role' => $punch->role, 'reverses_id' => $punch->reverses_id,
                ])->all();
                $dailyDecisions = $decisions->get($key, collect())->map(fn (DayDecision $decision): array => [
                    'id' => $decision->id, 'decision' => $decision->decision,
                    'created_at' => $decision->created_at->format('Y-m-d H:i:s'),
                ])->all();
                $candidate = $this->calculator->calculate($input, $policies[$date], $dailyPunches, $dailyManual, $dailyDecisions, false, false, $date, $now);
                $required = $candidate['required_leave_at'];
                $until = $required === null ? null : CarbonImmutable::parse($required)->addMinutes((int) $policies[$date]['close_grace_minutes'])->format('Y-m-d H:i:s');
                $covered = $until !== null && $this->coverage->covers($branchId, $date, $until);
                $outage = false;
                if ($covered && $now >= CarbonImmutable::parse($until) && $candidate['status'] !== 'rest_day'
                    && $candidate['status'] !== 'pattern_missing' && $branchId !== null) {
                    $outageKey = $branchId.'|'.$date;
                    $outageCache[$outageKey] ??= $this->outages->run($company, $branchId, $date, $date.' 23:59:59', true, true);
                    $outage = $outageCache[$outageKey];
                }
                $result = $this->calculator->calculate($input, $policies[$date], $dailyPunches, $dailyManual, $dailyDecisions, $covered, $outage, $date, $now);
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
