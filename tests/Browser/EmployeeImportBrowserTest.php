<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

// Pest's in-process browser server keeps a multipart field named `files[]` flat, so Livewire's temporary upload
// cannot be driven from here; the upload → mapping → preview → commit flow is covered in MappedEmployeeImportTest.
test('HR opens the employee import page with its upload options', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    app(TenantContext::class)->clear();

    visitBound('/login')
        ->fill('email', $user->email)
        ->fill('password', 'password')
        ->press('Log in')
        // Wait for the signed-in home page: navigating earlier races the login request.
        ->assertSee('Attendance dashboard')
        ->navigate('/admin/'.$company->id.'/employees/import')
        ->assertSee('Employee file')
        ->assertSee('Map columns')
        ->assertNoJavaScriptErrors();
});
