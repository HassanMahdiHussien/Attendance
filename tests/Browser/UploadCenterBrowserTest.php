<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

// One case per run: a second /login in the same browser is already signed in and skips the form.
test('the Upload page is available in both languages at desktop and phone width', function (string $locale, bool $phone): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff, 'locale' => $locale]);
    app(TenantContext::class)->clear();

    $browser = visitBound('/login');
    if ($phone) {
        $browser = $browser->on()->mobile()->resize(390, 844);
    }
    $browser->fill('email', $user->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')
        ->navigate('/admin/'.$company->id.'/upload')
        ->assertSee($locale === 'en' ? 'Choose reports' : 'اختر التقارير')
        ->assertNoJavaScriptErrors()
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertScript('document.documentElement.dir', $locale === 'ar' ? 'rtl' : 'ltr');
})->with([
    'English desktop' => ['en', false],
    'English phone' => ['en', true],
    'Arabic desktop' => ['ar', false],
    'Arabic phone' => ['ar', true],
]);
