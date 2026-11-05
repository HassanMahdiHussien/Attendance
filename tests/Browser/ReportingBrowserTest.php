<?php

use App\Enums\UserRole;
use App\Models\Attendance\Day;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

test('HR opens the attendance dashboard and an employee record', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $employee = app(TenantContext::class)->runForCompany($company, fn () => Employee::factory()->create());
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    app(TenantContext::class)->clear();

    visitBound('/login')->fill('email', $hr->email)->fill('password', 'password')->press('Log in')
        ->assertSee('Attendance dashboard')
        ->navigate('/dashboard')->assertSee('Needed from you')->assertNoJavaScriptErrors()
        ->navigate('/employees/'.$employee->id)->assertSee('Daily record')->assertNoJavaScriptErrors();
});

test('HR sees overtime on the dashboard and employee record', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $employee = app(TenantContext::class)->runForCompany($company, function (): Employee {
        $employee = Employee::factory()->create(['work_pattern' => 'office']);
        Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
            'work_date' => today('Asia/Baghdad')->toDateString(), 'status' => 'present',
            'overtime_minutes' => 45, 'overtime_basis' => 'after_end',
            'inputs' => ['work_pattern' => 'office']]);

        return $employee;
    });
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    app(TenantContext::class)->clear();

    visitBound('/login')->fill('email', $hr->email)->fill('password', 'password')->press('Log in')
        ->assertSee('Attendance dashboard')
        ->navigate('/dashboard')->click('details:has(> summary:has-text("More figures")) > summary')
        ->assertSee('Overtime hours')->assertSee('45 min')->assertNoJavaScriptErrors()
        ->navigate('/employees/'.$employee->id)->click('details:has(> summary:has-text("More figures")) > summary')
        ->assertSee('Overtime hours')->assertNoJavaScriptErrors();
});
