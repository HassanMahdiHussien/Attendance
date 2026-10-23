<?php

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

test('report filters default to the Baghdad month and survive queue serialization', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);
        $filters = ReportFilters::fromArray($company, [
            'employee_ids' => [$employee->id], 'branch_ids' => [$branch->id],
            'departments' => [$employee->department], 'work_patterns' => ['office'], 'statuses' => ['absent'],
        ]);
        expect($filters->from)->toBe('2026-10-01')->and($filters->to)->toBe('2026-10-23')
            ->and(ReportFilters::fromArray($company, $filters->toArray())->toArray())->toBe($filters->toArray());
    });
});

test('report filters reject foreign ids, reversed dates and a foreign tenant context', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $foreign = app(TenantContext::class)->runForCompany($other, fn () => Employee::factory()->create());
    app(TenantContext::class)->runForCompany($company, function () use ($company, $foreign): void {
        expect(fn () => ReportFilters::fromArray($company, ['employee_ids' => [$foreign->id]]))->toThrow(ValidationException::class)
            ->and(fn () => ReportFilters::fromArray($company, ['branch_ids' => [$foreign->branch_id]]))->toThrow(ValidationException::class)
            ->and(fn () => ReportFilters::fromArray($company, ['manager_employee_ids' => [$foreign->id]]))->toThrow(ValidationException::class)
            ->and(fn () => ReportFilters::fromArray($company, ['company_id' => $foreign->company_id]))->toThrow(ValidationException::class)
            ->and(fn () => ReportFilters::fromArray($company, ['from' => '2026-10-24', 'to' => '2026-10-23']))->toThrow(ValidationException::class);
    });
    app(TenantContext::class)->runForCompany($other, fn () => ReportFilters::fromArray($company, []));
})->throws(HttpException::class);
