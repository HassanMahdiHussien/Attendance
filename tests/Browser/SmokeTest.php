<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

test('an HR user signs in and opens the main pages without browser errors', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff,
        'email' => 'admin@example.test']);

    $page = visitBound('/login')
        ->fill('email', $admin->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertPathIs('/home')
        ->assertNoJavaScriptErrors();

    foreach (['/home', '/dashboard', '/employees'] as $path) {
        $page->navigate($path)->assertPathIs($path)->assertNoJavaScriptErrors();
    }
});
