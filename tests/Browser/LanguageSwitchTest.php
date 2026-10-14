<?php

use App\Enums\UserRole;
use App\Filament\Admin\Resources\BranchResource;
use App\Models\Company;
use App\Models\User;

test('the workspace language switch keeps the current page in both directions', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);

    $page = visitBound('/login')->assertNoJavaScriptErrors()
        ->fill('email', $admin->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors();
    $page->navigate('/cases')->assertPathIs('/cases')->assertNoJavaScriptErrors()
        ->click('#workspace-account > button')->click('#workspace-account-menu button[lang]')
        ->assertPathIs('/cases')
        ->assertScript('document.documentElement.dir', 'rtl')
        ->assertNoJavaScriptErrors()
        ->click('#workspace-account > button')->click('#workspace-account-menu button[lang]')
        ->assertPathIs('/cases')
        ->assertScript('document.documentElement.dir', 'ltr')
        ->assertNoJavaScriptErrors();
});

test('the admin panel user menu switches languages on the same tenant page', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $path = parse_url(BranchResource::getUrl(panel: 'admin', tenant: $company), PHP_URL_PATH);

    $page = visitBound('/login')->assertNoJavaScriptErrors()
        ->fill('email', $admin->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors();
    $page->click('button[aria-controls="workspace-settings"]')
        ->click('#workspace-settings a[href$="/branches"]')
        ->assertPathIs($path)->assertSee('New Branch')->assertNoJavaScriptErrors()
        ->click('.fi-user-menu-trigger')->click('Arabic')
        ->assertPathIs($path)
        ->assertScript('document.documentElement.dir', 'rtl')
        ->assertNoJavaScriptErrors()
        ->click('.fi-user-menu-trigger')->click('English')
        ->assertPathIs($path)
        ->assertScript('document.documentElement.dir', 'ltr')
        ->assertNoJavaScriptErrors();
});
