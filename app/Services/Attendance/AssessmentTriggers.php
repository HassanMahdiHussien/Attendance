<?php

namespace App\Services\Attendance;

use App\Events\AttendanceProcessed;
use App\Events\CoverageChanged;
use App\Events\EmployeeExitChanged;
use App\Events\EmployeeVersionChanged;
use App\Jobs\Attendance\AssessDaysJob;
use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\Person;
use App\Models\Attendance\Policy;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class AssessmentTriggers
{
    public function attendanceProcessed(AttendanceProcessed $event): void
    {
        $company = Company::findOrFail($event->companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($event, $company): void {
            $people = Person::query()->where('company_id', $company->id)
                ->whereIn('person_code', $event->personCodes)->whereNotNull('employee_id')->pluck('employee_id')->all();
            if ($people !== []) {
                $this->dispatch($company->id, ['employee_ids' => $people, 'from' => substr($event->from, 0, 10), 'to' => substr($event->to, 0, 10)], 'punches');
            }
            $branches = CoverageInterval::query()->where('company_id', $company->id)
                ->where('coverage_to', '>=', $event->from)->where('coverage_from', '<=', $event->to)
                ->distinct()->pluck('branch_id')->all();
            if ($branches !== []) {
                $this->dispatch($company->id, ['branch_ids' => $branches, 'from' => substr($event->from, 0, 10), 'to' => substr($event->to, 0, 10)], 'punches');
            }
        });
    }

    public function coverageChanged(CoverageChanged $event): void
    {
        $this->dispatch($event->companyId, [
            'branch_ids' => $event->branchIds, 'from' => substr($event->from, 0, 10), 'to' => substr($event->to, 0, 10),
        ], 'coverage');
    }

    public function employeeExitChanged(EmployeeExitChanged $event): void
    {
        $this->employeeFrom($event->employee->company_id, $event->employee->id, $event->from, 'exit');
    }

    public function employeeVersionChanged(EmployeeVersionChanged $event): void
    {
        $companyId = (int) $event->employee->company_id;
        $employeeId = (int) $event->employee->id;
        $from = $event->effectiveFrom->toDateString();
        DB::afterCommit(fn () => $this->employeeFrom($companyId, $employeeId, $from, 'employee'));
    }

    public function policyAdded(Policy $policy): void
    {
        $company = Company::findOrFail($policy->company_id);
        app(TenantContext::class)->runForCompany($company, function () use ($policy, $company): void {
            $next = Policy::query()->where('company_id', $company->id)
                ->where('effective_from', '>', $policy->effective_from->toDateString())
                ->orderBy('effective_from')->first();
            $lastCoverage = CoverageInterval::query()->where('company_id', $company->id)->max('coverage_to');
            if ($lastCoverage === null) {
                return;
            }
            $to = min(
                substr($lastCoverage, 0, 10),
                $next === null ? today()->toDateString() : CarbonImmutable::parse($next->effective_from)->subDay()->toDateString(),
            );
            if ($to >= $policy->effective_from->toDateString()) {
                $this->dispatch($company->id, [
                    'from' => $policy->effective_from->toDateString(), 'to' => $to,
                ], 'policy');
            }
        });
    }

    private function employeeFrom(int $companyId, int $employeeId, string $from, string $trigger): void
    {
        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($companyId, $employeeId, $from, $trigger): void {
            $firstCoverage = CoverageInterval::query()->where('company_id', $companyId)->min('coverage_from');
            $to = CoverageInterval::query()->where('company_id', $companyId)->max('coverage_to');
            $lastDay = DB::table('attendance_days')->where('company_id', $companyId)
                ->where('employee_id', $employeeId)->max('work_date');
            $end = max(substr((string) $to, 0, 10), (string) $lastDay);
            $start = max($from, substr((string) $firstCoverage, 0, 10));
            if ($start <= $end) {
                $this->dispatch($companyId, ['employee_ids' => [$employeeId], 'from' => $start, 'to' => $end], $trigger);
            }
        });
    }

    private function dispatch(int $companyId, array $scope, string $trigger): void
    {
        $scope['to'] = min($scope['to'], today('Asia/Baghdad')->toDateString());
        if (($scope['from'] ?? '') > ($scope['to'] ?? '')) {
            return;
        }
        AssessDaysJob::dispatch($companyId, $scope, $trigger)->afterCommit();
    }
}
