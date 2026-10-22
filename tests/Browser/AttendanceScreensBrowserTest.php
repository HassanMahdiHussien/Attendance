<?php

use App\Enums\UserRole;
use App\Models\Company;
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
