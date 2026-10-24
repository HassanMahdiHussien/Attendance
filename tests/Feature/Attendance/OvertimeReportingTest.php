<?php

use App\Models\Attendance\Day;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
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
