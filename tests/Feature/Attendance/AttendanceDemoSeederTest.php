<?php

use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\Transaction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\Balance;
use App\Models\Leave\LeaveRequest;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\AttendanceDemoSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalUsersSeeder;

test('a fresh demo seed fills the attendance screens through imports', function (): void {
    config(['app.seed_default_password' => 'fictional-demo-password']);
    config(['attendance.seed_demo_in_tests' => true]);
    $this->seed(DatabaseSeeder::class);

    $company = Company::query()->where('name', LocalUsersSeeder::DEMO_COMPANY_NAME)->firstOrFail();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        expect(Branch::query()->where('company_id', $company->id)->pluck('name')->all())
            ->toContain('الحلة', 'كربلاء', 'أربيل', 'بغداد');
        expect(Employee::query()->where('company_id', $company->id)->count())->toBe(52);
        expect(Transaction::query()->where('company_id', $company->id)->count())->toBeGreaterThan(100);
        expect(Balance::query()->where('company_id', $company->id)->count())->toBe(52)
            ->and(Holiday::query()->where('company_id', $company->id)->where('status', 'approved')->count())->toBe(2);
        $days = Day::query()->where('company_id', $company->id)->whereNull('superseded_by_run_id');
        expect((clone $days)->count())->toBeGreaterThan(100)
            ->and((clone $days)->where('late_minutes', '>', 0)->exists())->toBeTrue()
            ->and((clone $days)->where('status', 'absent')->exists())->toBeTrue()
            ->and((clone $days)->where('status', 'missing_punch')->exists())->toBeTrue()
            ->and((clone $days)->where('status', 'present')->where('reviewed', false)->exists())->toBeTrue()
            ->and((clone $days)->where('overtime_minutes', '>', 0)->exists())->toBeTrue();
        expect(LeaveRequest::query()->where('company_id', $company->id)->distinct()->pluck('status')->all())
            ->toContain('approved', 'pending', 'rejected');
        expect(Allocation::query()->where('company_id', $company->id)->exists())->toBeTrue();
        expect(DataIssue::query()->where('company_id', $company->id)->where('type', 'device_outage')->exists())->toBeTrue();
    });
});

test('demo attendance seeding refuses production', function (): void {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => app(AttendanceDemoSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'Demo seeders cannot run in production.');
});
