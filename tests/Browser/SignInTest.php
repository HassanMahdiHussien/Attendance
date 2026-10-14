<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
use App\Models\User;

test('each active role signs in through the shared login', function (UserRole $role, string $landing): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $attributes = ['primary_role' => $role];
    if (in_array($role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::DeptManager,
        UserRole::Reporter, UserRole::CommitteeMember], true)) {
        $attributes['company_id'] = $company->id;
    } elseif ($role === UserRole::ExternalLegalReviewer) {
        $attributes['law_firm_partner_id'] = LawFirmPartner::factory()->create()->id;
    } elseif ($role === UserRole::HrPartnerStaff) {
        $attributes['hr_partner_id'] = HrPartner::factory()->create()->id;
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
    'company admin' => [UserRole::CompanyAdmin, '/home'],
    'HR staff' => [UserRole::HrStaff, '/home'],
    'department manager' => [UserRole::DeptManager, '/home'],
    'reporter' => [UserRole::Reporter, '/home'],
    'committee member' => [UserRole::CommitteeMember, '/home'],
    'external legal reviewer' => [UserRole::ExternalLegalReviewer, '/home'],
    'HR partner' => [UserRole::HrPartnerStaff, '/home'],
]);

test('a wrong password shows the generic login error', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);

    visitBound('/login')
        ->assertNoJavaScriptErrors()
        ->fill('email', $user->email)
        ->fill('password', 'incorrect-password')
        ->press('Log in')
        ->assertPathIs('/login')
        ->assertSee(__('filament-panels::auth/pages/login.messages.failed'))
        ->assertNoJavaScriptErrors();
});

test('an Arabic reporter signs in at a narrow viewport', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create([
        'company_id' => $company->id,
        'primary_role' => UserRole::Reporter,
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
