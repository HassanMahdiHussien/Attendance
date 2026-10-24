<?php

use App\Events\DaysAssessed;
use App\Models\Attendance\Day;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Policy;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\OfficePolicy;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

test('reassessing rest-day work supersedes the entitlement once and retains the old row', function (): void {
    Carbon::setTestNow('2026-10-20 12:00:00');
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create([
            'branch_id' => $branch->id, 'hire_date' => '2020-01-01',
            'work_pattern' => 'office', 'rest_days' => [5, 6],
        ]);
        $hr = User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => $company->id]);
        $in = ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $hr->id,
            'punched_at' => '2026-10-16 08:00:00', 'role' => 'check_in']);
        $out = ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $hr->id,
            'punched_at' => '2026-10-16 12:00:00', 'role' => 'check_out']);
        $assess = function () use ($company, $employee): void {
            app(DayAssessor::class)->run($company, [
                'employee_ids' => [$employee->id], 'from' => '2026-10-16', 'to' => '2026-10-16',
            ], 'test');
        };
        $assess();
        $first = RestDayEntitlement::query()->whereNull('superseded_by_run_id')->firstOrFail();
        expect($first->quantity)->toBe('0.5')
            ->and($first->worked_minutes)->toBe(240)
            ->and(Day::query()->whereNull('superseded_by_run_id')->whereDate('work_date', '2026-10-16')->firstOrFail()->overtime_minutes)->toBe(240);

        $assess();
        expect(RestDayEntitlement::count())->toBe(1);

        ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $hr->id,
            'punched_at' => '2026-10-16 12:00:00', 'role' => 'check_out', 'reverses_id' => $out->id]);
        ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $hr->id,
            'punched_at' => '2026-10-16 16:00:00', 'role' => 'check_out']);
        $assess();
        expect($first->fresh()->superseded_by_run_id)->not->toBeNull()
            ->and(RestDayEntitlement::count())->toBe(2)
            ->and(RestDayEntitlement::query()->whereNull('superseded_by_run_id')->firstOrFail()->quantity)->toBe('1.0');
        $assess();
        expect(RestDayEntitlement::count())->toBe(2);
    });
});

test('policy replacements preserve history, win on the same date and are idempotent', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        Policy::factory()->create(['company_id' => $company->id, 'effective_from' => '2000-01-01',
            'version' => 1, 'flex_minutes' => 45]);
        Policy::factory()->create(['company_id' => $company->id, 'effective_from' => '2026-01-01',
            'version' => 2, 'flex_minutes' => 30]);
    });
    $migration = require database_path('migrations/2026_10_24_050000_replace_overtime_attendance_policies.php');
    $migration->up();
    $migration->up();

    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        expect(Policy::query()->count())->toBe(4)
            ->and(app(OfficePolicy::class)->asOf($company, '2025-01-01')->version)->toBe(3)
            ->and(app(OfficePolicy::class)->asOf($company, '2026-10-01')->version)->toBe(4)
            ->and(app(OfficePolicy::class)->asOf($company, '2026-10-01')->flex_minutes)->toBe(30)
            ->and(app(OfficePolicy::class)->asOf($company, '2026-10-01')->compensatory_rest_days['office'])->toBe([5]);
    });
});

test('security eligibility follows historical individual rest days', function (): void {
    Carbon::setTestNow('2026-10-20 12:00:00');
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $employee = Employee::factory()->create([
            'hire_date' => '2020-01-01', 'work_pattern' => 'security', 'rest_days' => [6],
        ]);
        $hr = User::factory()->create(['primary_role' => 'hr_staff', 'company_id' => $company->id]);
        app(EmployeeHistory::class)->record($employee, ['rest_days' => [5]], '2026-10-18', 'manual', $hr);
        foreach (['2026-10-17', '2026-10-18'] as $date) {
            ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $hr->id,
                'punched_at' => $date.' 08:00:00', 'role' => 'check_in']);
            ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $hr->id,
                'punched_at' => $date.' 16:00:00', 'role' => 'check_out']);
        }
        app(DayAssessor::class)->run($company, ['employee_ids' => [$employee->id],
            'from' => '2026-10-17', 'to' => '2026-10-18'], 'test');

        expect(RestDayEntitlement::query()->whereNull('superseded_by_run_id')->pluck('work_date')
            ->map(fn ($date): string => $date->toDateString())->all())->toBe(['2026-10-17']);
    });
});

test('repairing an entitlement without a changed day value still dispatches reconciliation', function (): void {
    Carbon::setTestNow('2026-10-20 12:00:00');
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $employee = Employee::factory()->create([
            'hire_date' => '2020-01-01', 'work_pattern' => 'office', 'rest_days' => [5, 6],
        ]);
        $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
        foreach (['08:00:00' => 'check_in', '12:00:00' => 'check_out'] as $time => $role) {
            ManualPunch::factory()->create([
                'employee_id' => $employee->id, 'created_by' => $hr->id,
                'punched_at' => '2026-10-16 '.$time, 'role' => $role,
            ]);
        }
        $scope = ['employee_ids' => [$employee->id], 'from' => '2026-10-16', 'to' => '2026-10-16'];
        app(DayAssessor::class)->run($company, $scope, 'test');
        $old = RestDayEntitlement::query()->whereNull('superseded_by_run_id')->firstOrFail();
        $run = ProcessingRun::factory()->create(['kind' => 'days']);
        $old->update(['superseded_by_run_id' => $run->id]);
        Event::fake([DaysAssessed::class]);

        app(DayAssessor::class)->run($company, $scope, 'test');
        Event::assertDispatched(DaysAssessed::class, fn (DaysAssessed $event): bool => $event->employeeIds === [$employee->id]);
        expect(RestDayEntitlement::query()->whereNull('superseded_by_run_id')->count())->toBe(1)
            ->and(RestDayEntitlement::count())->toBe(2);
    });
});
