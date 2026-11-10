<?php

use App\Models\Attendance\Day;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\Leave\EntitlementConsumption;
use App\Models\Leave\LeaveRequest;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ResultCache;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

test('period totals use current historical days and overdue starts on the second Sunday', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, function (): void {
        $employee = Employee::factory()->create();
        Day::factory()->create(['employee_id' => $employee->id, 'work_date' => '2026-10-16',
            'status' => 'rest_day_worked', 'overtime_minutes' => 900, 'overtime_basis' => 'rest_day']);
    });
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $oldBranch = Branch::factory()->create();
        $currentBranch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $currentBranch->id,
            'hire_date' => '2020-01-01', 'work_pattern' => 'office']);
        $day = Day::factory()->create([
            'employee_id' => $employee->id, 'branch_id' => $oldBranch->id,
            'work_date' => '2026-10-16', 'status' => 'rest_day_worked',
            'overtime_minutes' => 240, 'overtime_basis' => 'rest_day', 'early_arrival_minutes' => 15,
            'inputs' => ['work_pattern' => 'office'],
        ]);
        RestDayEntitlement::factory()->create([
            'employee_id' => $employee->id, 'source_day_id' => $day->id,
            'work_date' => '2026-10-16', 'quantity' => 0.5,
        ]);
        $filters = ReportFilters::fromArray($company, [
            'from' => '2026-10-16', 'to' => '2026-10-16',
            'branch_ids' => [$oldBranch->id], 'work_patterns' => ['office'],
            'statuses' => ['rest_day_worked'],
        ]);
        $service = app(Overtime::class);
        $before = $service->totals($filters, '2026-10-24');
        $after = $service->totals($filters, '2026-10-25');
        $ledger = iterator_to_array($service->entitlements($filters, '2026-10-25'));

        expect($before['overtime_minutes'])->toBe(240)
            ->and($before['early_arrival_days'])->toBe(1)
            ->and($before['entitlements_earned'])->toBe(0.5)
            ->and($before['entitlements_available_now'])->toBe(0.5)
            ->and($before['entitlements_overdue_now'])->toBe(0.0)
            ->and($after['entitlements_overdue_now'])->toBe(0.5)
            ->and($service->drillDownDayIds($filters, 'rest_day'))->toBe([$day->id])
            ->and($service->drillDownEmployeeIds($filters, 'entitlements_overdue_now', '2026-10-25'))->toBe([$employee->id])
            ->and($ledger[0]['earned'])->toBe(0.5)
            ->and($ledger[0]['available'])->toBe(0.5)
            ->and($ledger[0]['overdue'])->toBe(0.5)
            ->and($ledger[0]['alert_on'])->toBe('2026-10-25')
            ->and(iterator_to_array($service->perDay($filters, $employee->id))[0]['overtime_minutes'])->toBe(240);
        $laterPeriod = ReportFilters::fromArray($company, [
            'from' => '2026-10-19', 'to' => '2026-10-20', 'branch_ids' => [$oldBranch->id],
            'work_patterns' => ['office'], 'statuses' => ['rest_day_worked'],
        ]);
        $later = $service->totals($laterPeriod, '2026-10-25');
        expect($later['entitlements_earned'])->toBe(0.0)
            ->and($later['entitlements_available_now'])->toBe(0.5);
    });
});

test('overtime reporting cannot use another company filter', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $filters = app(TenantContext::class)->runForCompany($other, fn () => ReportFilters::fromArray($other, [
        'from' => '2026-10-16', 'to' => '2026-10-16',
    ]));

    app(TenantContext::class)->runForCompany($company, function () use ($filters): void {
        expect(fn () => app(Overtime::class)->totals($filters))->toThrow(HttpException::class);
    });
});

test('entitlement source days retain historical filters and current balance rules', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $includedBranch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $includedBranch->id, 'department' => 'Operations']);
        EmployeeVersion::factory()->create(['employee_id' => $employee->id, 'effective_from' => '2026-01-01',
            'changes' => ['department' => 'Sales']]);
        EmployeeVersion::factory()->create(['employee_id' => $employee->id, 'effective_from' => '2026-10-19',
            'changes' => ['department' => 'Operations']]);
        $make = function (string $date, int $branchId, string $status = 'rest_day_worked') use ($employee): Day {
            return Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branchId,
                'work_date' => $date, 'status' => $status, 'inputs' => ['work_pattern' => 'office']]);
        };
        $older = $make('2026-10-10', $includedBranch->id);
        $period = $make('2026-10-16', $includedBranch->id);
        $future = $make('2026-10-18', $includedBranch->id);
        $wrongBranch = $make('2026-10-17', $otherBranch->id);
        $wrongStatus = $make('2026-10-15', $includedBranch->id, 'present');
        $wrongDepartment = $make('2026-10-20', $includedBranch->id);
        foreach ([$older, $period, $future, $wrongBranch, $wrongStatus, $wrongDepartment] as $day) {
            RestDayEntitlement::factory()->create(['employee_id' => $employee->id, 'source_day_id' => $day->id,
                'work_date' => $day->work_date, 'quantity' => 0.5]);
        }
        $supersededDay = $make('2026-10-14', $includedBranch->id);
        RestDayEntitlement::factory()->create(['employee_id' => $employee->id, 'source_day_id' => $supersededDay->id,
            'work_date' => $supersededDay->work_date, 'quantity' => 0.5,
            'superseded_by_run_id' => ProcessingRun::factory()->create(['kind' => 'days'])->id]);
        $request = LeaveRequest::factory()->create(['employee_id' => $employee->id]);
        $olderEntitlement = RestDayEntitlement::query()->where('source_day_id', $older->id)->firstOrFail();
        EntitlementConsumption::factory()->create(['employee_id' => $employee->id, 'request_id' => $request->id,
            'entitlement_id' => $olderEntitlement->id, 'quantity' => 0.5]);
        $filters = ReportFilters::fromArray($company, ['from' => '2026-10-14', 'to' => '2026-10-20',
            'branch_ids' => [$includedBranch->id], 'departments' => ['Sales'],
            'statuses' => ['rest_day_worked'], 'work_patterns' => ['office']]);
        $totals = app(Overtime::class)->totals($filters, '2026-10-17');

        expect($totals['entitlements_earned'])->toBe(1.0)
            ->and($totals['entitlements_used'])->toBe(0.5)
            ->and($totals['entitlements_available_now'])->toBe(0.5)
            ->and($totals['entitlements_overdue_now'])->toBe(0.0)
            ->and($totals['entitlement_ids'])->toHaveCount(2);
    });
});

test('overtime query count does not grow with unrelated historical days', function (): void {
    $company = Company::factory()->create();
    // Measures the uncached work: a closed past range would otherwise be served from the result cache.
    app(TenantContext::class)->runForCompany($company, fn () => app(ResultCache::class)->withoutCache(function () use ($company): void {
        $employee = Employee::factory()->create();
        $filters = ReportFilters::fromArray($company, ['from' => '2026-10-16', 'to' => '2026-10-16']);
        Day::factory()->create(['employee_id' => $employee->id, 'work_date' => '2026-10-16']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(AttendanceSummary::class)->totals($filters);
        app(Overtime::class)->totals($filters, '2026-10-20');
        $before = DB::getQueryLog();
        DB::flushQueryLog();
        foreach (range(1, 30) as $offset) {
            Day::factory()->create(['employee_id' => $employee->id,
                'work_date' => '2026-09-'.str_pad((string) $offset, 2, '0', STR_PAD_LEFT)]);
        }
        DB::flushQueryLog();
        app(AttendanceSummary::class)->totals($filters);
        app(Overtime::class)->totals($filters, '2026-10-20');
        $after = DB::getQueryLog();
        DB::disableQueryLog();
        $count = fn (array $queries, string $table): int => count(array_filter($queries,
            fn (array $query): bool => str_contains(strtolower($query['query']), 'from `'.$table.'`')));

        expect($count($before, 'attendance_days'))->toBe($count($after, 'attendance_days'))
            ->and($count($before, 'attendance_rest_day_entitlements'))->toBe($count($after, 'attendance_rest_day_entitlements'))
            ->and($count($after, 'attendance_rest_day_entitlements'))->toBeLessThanOrEqual(2);
    }));
});
