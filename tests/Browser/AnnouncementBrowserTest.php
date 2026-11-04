<?php

use App\Enums\UserRole;
use App\Models\Attendance\HolidaySource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

test('HR reviews an announcement on a narrow screen in both directions', function (string $locale): void {
    $company = Company::factory()->create();
    $setup = completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id,
        'primary_role' => UserRole::HrStaff, 'locale' => $locale]);
    app(TenantContext::class)->runForCompany($company, function () use ($company, $setup, $user): void {
        Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $setup['branch']->id,
            'employee_code' => '00004567', 'department' => 'Events', 'hire_date' => '2020-01-01']);
        HolidaySource::factory()->create(['company_id' => $company->id, 'uploaded_by' => $user->id,
            'ai_status' => 'done', 'file_name' => 'market.eml', 'proposal' => [
                'kind' => 'field_work', 'summary' => 'Events staff work away from devices.',
                'name_en' => 'Market day', 'name_ar' => null,
                'ranges' => [['from' => '2026-10-08', 'to' => '2026-10-08']],
                'departments' => ['Events'], 'suggested_departments' => [],
                'legal_entities' => [], 'branch_names' => [], 'work_patterns' => [],
                'scope_quotes' => ['Events staff work at the market.'], 'exception_quotes' => [],
                'confidence' => 'high', 'notes' => [], 'expected_people' => 1,
                'mentions_missing_attachment' => false,
            ]]);
    });
    $source = app(TenantContext::class)->runForCompany($company,
        fn () => HolidaySource::query()->where('file_name', 'market.eml')->firstOrFail());
    app(TenantContext::class)->clear();

    $browser = visitBound('/login')->on()->mobile()->resize(390, 844)
        ->fill('email', $user->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')
        ->navigate('/admin/'.$company->id.'/attendance/holidays/upload?source='.$source->id)
        ->assertSee($locale === 'en' ? 'People covered' : 'الموظفون المشمولون')
        ->assertSee('00004567')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript('document.documentElement.dir', $locale === 'ar' ? 'rtl' : 'ltr')
        ->assertNoJavaScriptErrors();
    if ($locale === 'en') {
        $browser->press('Approve')->assertSee('is approved')
            ->assertScript("document.body.innerText.includes('Recalculating') || document.body.innerText.includes('Done.')", true)
            ->assertNoJavaScriptErrors();
    }
})->with(['English' => ['en'], 'Arabic' => ['ar']]);
