<?php

use App\Enums\UserRole;
use App\Models\Attendance\Day;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

test('HR opens the five attendance pages', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    app(TenantContext::class)->clear();

    $browser = visitBound('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertSee('Attendance dashboard');
    foreach (['upload' => 'Upload attendance', 'batches' => 'Attendance batches', 'devices' => 'Devices', 'issues' => 'Data issues', 'punches' => 'Punch viewer'] as $slug => $title) {
        $browser->navigate('/admin/'.$company->id.'/attendance/'.$slug)->assertSee($title)->assertNoJavaScriptErrors();
    }
});

test('HR opens the four office attendance pages', function (): void {
    $company = Company::factory()->create();
    $setup = completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $employee = Employee::factory()->create([
        'company_id' => $company->id, 'branch_id' => $setup['branch']->id, 'hire_date' => '2026-10-20',
    ]);
    Day::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id,
        'branch_id' => $setup['branch']->id, 'work_date' => '2026-10-21']);
    app(TenantContext::class)->clear();

    $browser = visitBound('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertSee('Attendance dashboard');
    foreach (['daily' => 'Daily attendance', 'review' => 'Review queue', 'office-policy' => 'Attendance policy'] as $slug => $title) {
        $browser->navigate('/admin/'.$company->id.'/attendance/'.$slug)->assertSee($title)->assertNoJavaScriptErrors();
    }
    // "Needs shift" is an option of the review filter: options are not visible text, so check the page source.
    $browser->navigate('/admin/'.$company->id.'/attendance/review')->assertSee('Review queue')
        ->assertSourceHas('Needs shift')->assertNoJavaScriptErrors();
    $browser->navigate('/admin/'.$company->id.'/attendance/office-policy')->assertSee('Shift')
        ->assertSee('Security')->assertNoJavaScriptErrors();
    $browser->navigate('/admin/'.$company->id.'/attendance/day?employee='.$employee->id.'&date=2026-10-21')
        ->assertSee('Attendance day')->assertNoJavaScriptErrors();
});

test('HR opens the leave pages', function (): void {
    $company = Company::factory()->create();
    $setup = completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $setup['branch']->id]);
    $request = LeaveRequest::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id,
        'owner_code' => $employee->employee_code]);
    app(TenantContext::class)->clear();

    $browser = visitBound('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertSee('Attendance dashboard');
    foreach (['upload' => 'Upload leave', 'batches' => 'Leave batches', 'requests' => 'Leave requests',
        'types' => 'Leave types', 'credits' => 'Credit ledger'] as $slug => $title) {
        $browser->navigate('/admin/'.$company->id.'/leave/'.$slug)->assertSee($title)->assertNoJavaScriptErrors();
    }
    $browser->navigate('/admin/'.$company->id.'/leave/request?request='.$request->id)
        ->assertSee('Leave request')->assertNoJavaScriptErrors();
});
