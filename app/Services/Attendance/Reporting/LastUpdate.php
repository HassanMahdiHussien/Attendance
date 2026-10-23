<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch as AttendanceBatch;
use App\Models\Attendance\ImportBatchEvent as AttendanceBatchEvent;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Policy;
use App\Models\Attendance\ProcessingRun as AttendanceRun;
use App\Models\Attendance\Punch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeVersion;
use App\Models\Leave\ImportBatch as LeaveBatch;
use App\Models\Leave\ImportBatchEvent as LeaveBatchEvent;
use App\Models\Leave\LeaveType;
use App\Models\Leave\ProcessingRun as LeaveRun;
use App\Models\Leave\RequestDecision;
use App\Models\Leave\Setting;
use App\Services\Attendance\CoverageWindows;
use App\Services\Attendance\OfficeDayCalculator;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

class LastUpdate
{
    public function __construct(private CoverageWindows $coverage, private OfficeDayCalculator $calculator, private EmployeeHistory $history) {}

    public function for(ReportFilters $filters): array
    {
        abort_unless(app(TenantContext::class)->companyId() === $filters->companyId, 403);
        $company = Company::findOrFail($filters->companyId);
        $employees = EmployeeImportBatch::query()->where('company_id', $company->id)->max('created_at');
        $punchBatch = AttendanceBatch::query()->where('company_id', $company->id)
            ->whereIn('id', AttendanceBatchEvent::query()->where('company_id', $company->id)
                ->where('state', 'completed')->select('batch_id'))
            ->orderByDesc('id')->first();
        $leaveBatch = LeaveBatch::query()->where('company_id', $company->id)
            ->whereIn('id', LeaveBatchEvent::query()->where('company_id', $company->id)
                ->where('state', 'completed')->select('batch_id'))
            ->orderByDesc('id')->first();
        $punches = $punchBatch === null ? null : AttendanceBatchEvent::query()->where('company_id', $company->id)
            ->where('batch_id', $punchBatch->id)->where('state', 'completed')->max('created_at');
        $leave = $leaveBatch === null ? null : LeaveBatchEvent::query()->where('company_id', $company->id)
            ->where('batch_id', $leaveBatch->id)->where('state', 'completed')->max('created_at');
        $cleanup = AttendanceRun::query()->where('company_id', $company->id)->where('kind', 'cleanup')
            ->where('status', 'completed')->max('finished_at');
        $assessment = AttendanceRun::query()->where('company_id', $company->id)->where('kind', 'days')
            ->where('status', 'completed')->max('finished_at');
        $reconciliation = LeaveRun::query()->where('company_id', $company->id)->where('status', 'completed')->max('finished_at');
        $assessmentInputs = array_filter([
            Policy::query()->where('company_id', $company->id)->max('created_at'),
            EmployeeVersion::query()->where('company_id', $company->id)->max('created_at'),
            CoverageInterval::query()->where('company_id', $company->id)->where('kind', 'declared')
                ->where('is_confirmed', true)->max('created_at'),
            ManualPunch::query()->where('company_id', $company->id)->max('created_at'),
            DayDecision::query()->where('company_id', $company->id)->max('created_at'),
        ]);
        $newestAssessmentInput = $assessmentInputs === [] ? null : max($assessmentInputs);
        $reconciliationInputs = array_filter([
            RequestDecision::query()->where('company_id', $company->id)->max('created_at'),
            LeaveType::query()->where('company_id', $company->id)->max('updated_at'),
            Setting::query()->where('company_id', $company->id)->max('updated_at'),
        ]);
        $newestReconciliationInput = $reconciliationInputs === [] ? null : max($reconciliationInputs);
        $latestDeviceSetting = DeviceSetting::query()->where('company_id', $company->id)->max('created_at');
        $sources = ['employees' => $employees, 'punches' => $punches, 'leave' => $leave];
        $runs = ['cleanup' => $cleanup, 'assessment' => $assessment, 'reconciliation' => $reconciliation];

        return [
            'sources' => $sources, 'runs' => $runs,
            'results_updating' => ($punches !== null && ($cleanup === null || $punches > $cleanup || $assessment === null || $punches > $assessment))
                || ($latestDeviceSetting !== null && ($cleanup === null || $latestDeviceSetting > $cleanup))
                || ($cleanup !== null && ($assessment === null || $cleanup > $assessment))
                || ($leave !== null && ($reconciliation === null || $leave > $reconciliation))
                || ($assessment !== null && ($reconciliation === null || $assessment > $reconciliation))
                || ($employees !== null && ($assessment === null || $employees > $assessment))
                || ($newestAssessmentInput !== null && ($assessment === null || $newestAssessmentInput > $assessment))
                || ($newestReconciliationInput !== null && ($reconciliation === null || $newestReconciliationInput > $reconciliation)),
            'coverage' => $this->coverageQuality($company, $filters),
        ];
    }

    private function coverageQuality(Company $company, ReportFilters $filters): array
    {
        $this->coverage->load($company, $filters->from, CarbonImmutable::parse($filters->to)->addDays(3)->toDateString());
        $now = CarbonImmutable::now('Asia/Baghdad');
        $dates = [];
        for ($day = CarbonImmutable::parse($filters->from); $day->toDateString() <= $filters->to; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        $policies = Policy::query()->where('company_id', $company->id)
            ->where('effective_from', '<=', $filters->to)->orderBy('effective_from')->orderBy('version')->get();
        $policyByDate = [];
        $index = 0;
        $current = null;
        foreach ($dates as $date) {
            while (isset($policies[$index]) && $policies[$index]->effective_from->toDateString() <= $date) {
                $current = $policies[$index]->toArray();
                $index++;
            }
            $policyByDate[$date] = $current;
        }
        $result = [];
        $employees = Employee::query()->where('company_id', $company->id);
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
            $versions = EmployeeVersion::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
                ->where('effective_from', '<=', $filters->to)->orderBy('effective_from')->orderBy('id')->get()->groupBy('employee_id');
            $punches = Punch::query()->where('company_id', $company->id)->whereIn('person_code', $chunk->pluck('employee_code'))
                ->whereNull('superseded_by_run_id')->where('status', 'used')
                ->whereBetween('punched_at', [$filters->from.' 00:00:00', $filters->to.' 23:59:59'])->get()
                ->groupBy(fn (Punch $punch): string => $punch->person_code.'|'.$punch->punched_at->toDateString());
            $manual = ManualPunch::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
                ->whereBetween('punched_at', [$filters->from.' 00:00:00', $filters->to.' 23:59:59'])->get()
                ->groupBy(fn (ManualPunch $punch): string => $punch->employee_id.'|'.$punch->punched_at->toDateString());
            $decisions = DayDecision::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
                ->whereBetween('work_date', [$filters->from, $filters->to])->get()
                ->groupBy(fn (DayDecision $decision): string => $decision->employee_id.'|'.$decision->work_date->toDateString());
            $stored = Day::query()->where('company_id', $company->id)->whereIn('employee_id', $ids)
                ->whereBetween('work_date', [$filters->from, $filters->to])->whereNull('superseded_by_run_id')->get()
                ->keyBy(fn (Day $day): string => $day->employee_id.'|'.$day->work_date->toDateString());
            foreach ($chunk as $employee) {
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
                    $policy = $policyByDate[$date];
                    if ($policy === null) {
                        continue;
                    }
                    $branch = $values['branch_id'] === null ? null : (int) $values['branch_id'];
                    if ($branch === null || ($filters->branchIds !== [] && ! in_array($branch, $filters->branchIds, true))
                        || ($filters->departments !== [] && ! in_array($values['department'], $filters->departments, true))
                        || ($filters->managerEmployeeIds !== [] && ! in_array((int) $values['manager_employee_id'], $filters->managerEmployeeIds, true))
                        || ($filters->workPatterns !== [] && ! in_array($values['work_pattern'], $filters->workPatterns, true))) {
                        continue;
                    }
                    $input = $values + ['exit_status' => $employee->exit_status->value,
                        'last_working_day' => $employee->last_working_day?->toDateString()];
                    $dailyPunches = $punches->get($employee->employee_code.'|'.$date, collect())
                        ->map(fn (Punch $punch): array => [
                            'id' => $punch->id, 'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                            'role' => $punch->role, 'status' => $punch->status,
                        ])->all();
                    $dailyManual = $manual->get($employee->id.'|'.$date, collect())
                        ->map(fn (ManualPunch $punch): array => [
                            'id' => $punch->id, 'punched_at' => $punch->punched_at->format('Y-m-d H:i:s'),
                            'role' => $punch->role, 'reverses_id' => $punch->reverses_id,
                        ])->all();
                    $dailyDecisions = $decisions->get($employee->id.'|'.$date, collect())
                        ->map(fn (DayDecision $decision): array => [
                            'id' => $decision->id, 'decision' => $decision->decision,
                            'created_at' => $decision->created_at->format('Y-m-d H:i:s'),
                        ])->all();
                    $candidate = $this->calculator->calculate($input, $policy, $dailyPunches, $dailyManual,
                        $dailyDecisions, false, false, $date, $now);
                    if (in_array($candidate['status'], ['rest_day', 'not_employed', 'pattern_missing'], true)
                        || $candidate['required_leave_at'] === null) {
                        continue;
                    }
                    $storedDay = $stored->get($employee->id.'|'.$date);
                    $required = $storedDay !== null && (int) $storedDay->policy_id === (int) $policy['id']
                        && (int) $storedDay->branch_id === $branch
                        ? ($storedDay->required_leave_at?->format('Y-m-d H:i:s') ?? $candidate['required_leave_at'])
                        : $candidate['required_leave_at'];
                    $until = CarbonImmutable::parse($required, 'Asia/Baghdad')->addMinutes((int) $policy['close_grace_minutes']);
                    $result[$branch] ??= ['expected' => 0, 'assessable' => 0, 'ratio' => 0.0];
                    $result[$branch]['expected']++;
                    if ($now >= $until && $this->coverage->covers($branch, $date, $until->format('Y-m-d H:i:s'))) {
                        $result[$branch]['assessable']++;
                    }
                }
            }
        }
        foreach ($result as &$branch) {
            $branch['ratio'] = $branch['expected'] === 0 ? 0.0 : $branch['assessable'] / $branch['expected'];
        }

        return $result;
    }
}
