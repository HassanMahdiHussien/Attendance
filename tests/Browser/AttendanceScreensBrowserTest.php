<?php

use App\Enums\UserRole;
use App\Models\Attendance\Day;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Models\Attendance\NotificationMessageCase;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\Holidays;
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

test('HR opens follow-up cases, notification drafts and approver mappings', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $employee = Employee::factory()->create(['company_id' => $company->id,
            'employee_code' => '00000123', 'fallback_notification_email' => 'follow-up@example.test']);
        $route = ['key' => 'manager', 'kind' => 'manager', 'request_id' => null, 'external_id' => null,
            'recipient_employee_id' => null, 'recipient_available' => true,
            'items' => [['type' => 'absence', 'minutes' => 480]]];
        $case = FollowUpCase::factory()->create(['company_id' => $company->id, 'employee_id' => $employee->id,
            'routes' => [$route]]);
        $batch = NotificationBatch::factory()->create(['company_id' => $company->id]);
        $message = NotificationMessage::factory()->create(['company_id' => $company->id,
            'batch_id' => $batch->id, 'address' => 'follow-up@example.test']);
        NotificationMessageCase::factory()->create(['company_id' => $company->id,
            'message_id' => $message->id, 'case_id' => $case->id,
            'snapshot' => ['employee_name' => $employee->name, 'employee_code' => $employee->employee_code,
                'work_date' => $case->work_date->toDateString(), 'items' => $route['items'],
                'route' => $route, 'address' => 'follow-up@example.test', 'recipient_employee_id' => null]]);
    });
    app(TenantContext::class)->clear();

    $browser = visitBound('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertSee('Attendance dashboard');
    foreach (['follow-up-cases' => 'Follow-up cases', 'notifications' => 'Notification settings',
        'approver-mappings' => 'Approver mappings'] as $slug => $title) {
        $browser->navigate('/admin/'.$company->id.'/attendance/'.$slug)
            ->assertSee($title)->assertNoJavaScriptErrors();
    }
    $browser->navigate('/admin/'.$company->id.'/attendance/notifications')
        ->press('Preview')->assertSee('Attendance follow-up')
        ->press('Send batch')->assertSee('Sent')->assertNoJavaScriptErrors();
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

test('HR opens leave balance upload and uploads pages', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    app(TenantContext::class)->clear();

    $browser = visitBound('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertSee('Attendance dashboard');
    $browser->navigate('/admin/'.$company->id.'/leave/balance/upload')
        ->assertSee('Upload leave balance')->assertNoJavaScriptErrors();
    $browser->navigate('/admin/'.$company->id.'/leave/balance/uploads')
        ->assertSee('Leave balance uploads')->assertNoJavaScriptErrors();
});

test('HR opens the holidays list and a draft review', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $draft = app(TenantContext::class)->runForCompany($company, function () use ($user, $company): Holiday {
        $this->actingAs($user);

        return app(Holidays::class)->create($company, [
            'name_en' => 'Founders Day',
            'ranges' => [['from' => '2026-10-19', 'to' => '2026-10-19']],
        ], $user);
    });
    app(TenantContext::class)->clear();
    app('auth')->forgetGuards();

    $browser = visitBound('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertSee('Attendance dashboard');
    $browser->navigate('/admin/'.$company->id.'/attendance/holidays')
        ->assertSee('Holidays')->assertSee('Founders Day')->assertNoJavaScriptErrors();
    $browser->navigate('/admin/'.$company->id.'/attendance/holidays/review?holiday='.$draft->id)
        ->assertSee('Holiday review')->assertSee('Founders Day')->assertNoJavaScriptErrors();
});
