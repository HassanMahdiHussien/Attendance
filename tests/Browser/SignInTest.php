<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

test('each active role signs in through the shared login', function (UserRole $role, string $landing): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $attributes = ['primary_role' => $role];
    if ($role === UserRole::HrStaff) {
        $attributes['company_id'] = $company->id;
    }
    $user = User::factory()->create($attributes);

    visitBound('/login')
        ->assertNoJavaScriptErrors()
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertPathIs($landing)
        ->assertNoJavaScriptErrors();
})->with([
    'platform admin' => [UserRole::PlatformAdmin, '/platform'],
    'HR staff' => [UserRole::HrStaff, '/home'],
]);

test('a wrong password shows the generic login error', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);

    visitBound('/login')
        ->assertNoJavaScriptErrors()
        ->fill('email', $user->email)
        ->fill('password', 'incorrect-password')
        ->press('Log in')
        ->assertPathIs('/login')
        ->assertSee(__('filament-panels::auth/pages/login.messages.failed'))
        ->assertNoJavaScriptErrors();
});

test('an Arabic HR user signs in at a narrow viewport', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'primary_role' => UserRole::HrStaff,
        'locale' => 'ar',
    ]);

    visitBound('/login')->on()->mobile()
        ->resize(375, 812)
        ->assertNoJavaScriptErrors()
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        ->assertPathIs('/home')
        ->assertScript('document.documentElement.dir', 'rtl')
        ->assertNoJavaScriptErrors();
});
