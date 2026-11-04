<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\DescriptionReading;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestVersion;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

test('HR can read an invented description at phone width in either direction', function (string $locale): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id,
        'primary_role' => UserRole::HrStaff, 'locale' => $locale]);
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $employee = Employee::factory()->create(['company_id' => $company->id,
            'employee_code' => '00004567']);
        $request = LeaveRequest::factory()->create(['company_id' => $company->id,
            'employee_id' => $employee->id, 'external_id' => 'FORM-INVENTED']);
        $batch = ImportBatch::factory()->create(['company_id' => $company->id]);
        $version = RequestVersion::factory()->create(['company_id' => $company->id,
            'request_id' => $request->id, 'batch_id' => $batch->id, 'row_number' => 2,
            'values' => ['description' => 'An invented personal reason'], 'raw_values' => []]);
        $request->update(['current_version_id' => $version->id]);
        DescriptionReading::create(['company_id' => $company->id, 'request_id' => $request->id,
            'text_hash' => hash('sha256', 'An invented personal reason'),
            'category' => 'reason_only', 'worked_dates' => [], 'confidence' => 'high',
            'status' => 'not_needed']);
    });
    app(TenantContext::class)->clear();

    visitBound('/login')->on()->mobile()->resize(390, 844)
        ->fill('email', $user->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')
        ->navigate('/admin/'.$company->id.'/leave/descriptions')
        ->assertSee('FORM-INVENTED')
        ->assertSee('An invented personal reason')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript('document.documentElement.dir', $locale === 'ar' ? 'rtl' : 'ltr')
        ->assertNoJavaScriptErrors();
})->with(['English' => ['en'], 'Arabic' => ['ar']]);
