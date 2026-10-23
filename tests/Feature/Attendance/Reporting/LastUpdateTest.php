<?php

use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\Day;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\ProcessingRun;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\OfficePolicy;
use App\Services\Attendance\Reporting\LastUpdate;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

test('freshness separates import and derived runs and coverage uses the required closing time', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $branch->id,
            'hire_date' => '2020-01-01', 'work_pattern' => 'office']);
        $policy = app(OfficePolicy::class)->createDefault($company);
        $batch = ImportBatch::factory()->create();
        ImportBatchEvent::create(['company_id' => $company->id, 'batch_id' => $batch->id,
            'sequence' => 1, 'state' => 'completed', 'created_at' => '2026-10-23 11:00:00']);
        foreach (['cleanup', 'days'] as $kind) {
            ProcessingRun::factory()->create(['kind' => $kind, 'finished_at' => '2026-10-23 10:00:00']);
        }
        CoverageInterval::factory()->create(['batch_id' => $batch->id, 'branch_id' => $branch->id,
            'coverage_from' => '2026-10-19 00:00:00', 'coverage_to' => '2026-10-19 19:00:00',
            'is_confirmed' => true]);
        foreach (['2026-10-19', '2026-10-20'] as $date) {
            Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
                'policy_id' => $policy->id, 'work_date' => $date,
                'required_leave_at' => $date.' 17:00:00', 'status' => 'absent']);
        }
        $filters = ReportFilters::fromArray($company, ['from' => '2026-10-19', 'to' => '2026-10-20']);
        $result = app(LastUpdate::class)->for($filters);
        expect($result['results_updating'])->toBeTrue()
            ->and($result['sources']['punches'])->toBe('2026-10-23 11:00:00')
            ->and($result['runs']['cleanup'])->toBe('2026-10-23 10:00:00')
            ->and($result['coverage'][$branch->id]['expected'])->toBe(2)
            ->and($result['coverage'][$branch->id]['assessable'])->toBe(1)
            ->and($result['coverage'][$branch->id]['ratio'])->toBe(0.5);
        $unassessed = app(LastUpdate::class)->for(ReportFilters::fromArray($company, [
            'from' => '2026-10-19', 'to' => '2026-10-21',
        ]));
        expect($unassessed['coverage'][$branch->id]['expected'])->toBe(3)
            ->and($unassessed['coverage'][$branch->id]['assessable'])->toBe(1);
    });
});
