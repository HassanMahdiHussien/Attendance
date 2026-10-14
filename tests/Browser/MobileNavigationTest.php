<?php

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;

test('the 375 pixel bottom navigation and More sheet navigate the workspace', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);

    $page = visitBound('/login')->on()->mobile()->resize(375, 812)->assertNoJavaScriptErrors()
        ->fill('email', $admin->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors();
    $page->click('nav.fixed a[href="/cases"]')
        ->assertPathIs('/cases')->assertNoJavaScriptErrors()
        ->click('nav.fixed button[aria-controls="workspace-more"]')
        ->assertVisible('#workspace-more')
        ->click('#workspace-more a[href="/reports/inbox"]')
        ->assertPathIs('/reports/inbox')
        ->assertNoJavaScriptErrors();
});

test('the Arabic case page fits a 375 pixel viewport', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $admin = User::factory()->create([
        'company_id' => $company->id,
        'primary_role' => UserRole::CompanyAdmin,
        'locale' => 'ar',
    ]);
    $case = DisciplinaryCase::factory()->create([
        'company_id' => $company->id,
        'opened_by_user_id' => $admin->id,
        'law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::Open,
    ]);

    $page = visitBound('/login')->on()->mobile()->resize(375, 812)->assertNoJavaScriptErrors()
        ->fill('email', $admin->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors();
    $page->navigate('/cases/'.$case->id)->assertPathIs('/cases/'.$case->id)
        ->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.dir', 'rtl')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth');
});
