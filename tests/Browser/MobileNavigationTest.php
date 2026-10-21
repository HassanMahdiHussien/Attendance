<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

test('the phone navigation opens the employee list and More sheet', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);

    visitBound('/login')->on()->mobile()->resize(375, 812)->assertNoJavaScriptErrors()
        ->fill('email', $admin->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors()
        ->click('nav.fixed a[href="/employees"]')
        ->assertPathIs('/employees')->assertNoJavaScriptErrors()
        ->click('nav.fixed button[aria-controls="workspace-more"]')
        ->assertVisible('#workspace-more');
});

test('the Arabic employee page fits a phone viewport', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create();
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'primary_role' => UserRole::HrStaff,
        'locale' => 'ar',
    ]);

    visitBound('/login')->on()->mobile()->resize(375, 812)->assertNoJavaScriptErrors()
        ->fill('email', $admin->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors()
        ->navigate('/employees/'.$employee->id)->assertPathIs('/employees/'.$employee->id)
        ->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.dir', 'rtl')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
});
