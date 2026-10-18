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

test('the shared header switches languages on the same admin page', function (): void {
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
        ->click('button[aria-controls="workspace-account-menu"]')->click('#workspace-account-menu button[lang="ar"]')
        ->assertPathIs($path)
        ->assertScript('document.documentElement.dir', 'rtl')
        ->assertNoJavaScriptErrors()
        ->click('button[aria-controls="workspace-account-menu"]')->click('#workspace-account-menu button[lang="en"]')
        ->assertPathIs($path)
        ->assertScript('document.documentElement.dir', 'ltr')
        ->assertNoJavaScriptErrors();
});
