<?php

use App\Enums\UserRole;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Law;
use App\Models\User;
use App\Models\ViolationReport;

test('a reporter checklist reaches the HR inbox and becomes an open case', function (): void {
    $company = Company::factory()->create();
    $setup = completeCompanySetup($company);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2025-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $branch = $setup['branch'];
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    config()->set('services.anthropic.key', null);

    $reporterPage = visitBound('/login')->assertNoJavaScriptErrors()
        ->fill('email', $reporter->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors();
    $reporterPage->navigate('/reports/new')->assertPathIs('/reports/new')->assertNoJavaScriptErrors()
        ->select('report-branch', $branch->id)
        ->select('report-employee', $employee->id)
        ->fill('report-place', 'Line 3')
        ->fill('report-occurred-at', '2026-10-09T03:00')
        ->fill('report-violation-type', 'Fight')
        ->fill('report-description', 'A fight occurred on Line 3.')
        ->press(__('messages.intake.check_description'))
        ->assertSee(__('messages.intake.checklist_fallback'))
        ->fill('intake-answer-0', 'Two workers were involved.')
        ->press('Send report')
        ->assertPathIs('/reports')
        ->assertNoJavaScriptErrors();

    $report = ViolationReport::query()->where('reporter_user_id', $reporter->id)->sole();
    expect($report->intakeQuestions()->whereNotNull('answer')->count())->toBe(1);

    $reporterPage->click('#workspace-account > button')->click('#workspace-account-menu form[action$="/logout"] button')
        ->assertPathIs('/login')->assertNoJavaScriptErrors();
    // A fresh login page: typing into the page reached through the logout redirect raced Livewire's boot.
    $hrPage = visitBound('/login')->waitForEvent('networkidle')
        ->fill('email', $hr->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors();
    $hrPage->navigate('/reports/inbox')->assertPathIs('/reports/inbox')->assertNoJavaScriptErrors()
        ->assertSee('A fight occurred on Line 3.')
        ->press('Convert to case')
        ->assertPathIs('/cases/new')
        ->assertNoJavaScriptErrors()
        ->assertSee('Report details are ready for review. Complete the fields and save the draft.')
        ->fill('description', 'HR confirmed the reported fight on Line 3.')
        ->select('assigned-hr', $hr->id)
        ->press('Check for duplicates')
        ->assertSee('Duplicate check result')
        ->press('Continue and open case');

    $case = DisciplinaryCase::query()->where('violation_report_id', $report->id)->sole();
    $hrPage->assertPathIs('/cases/'.$case->id)
        ->assertNoJavaScriptErrors()
        ->assertSee($employee->name);
});
