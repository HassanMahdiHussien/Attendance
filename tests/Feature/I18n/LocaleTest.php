<?php

use App\Enums\UserRole;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranchs;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Support\Format\Ui;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

test('guest pages default to English and keep a session language choice', function (): void {
    $this->get('/')->assertOk()->assertSee('lang="en" dir="ltr"', false)->assertSee('Attendance and leave reconciliation')->assertSee('العربية');

    $this->post('/locale', ['locale' => 'ar'])->assertRedirect();
    $this->get('/login')->assertOk()->assertSee('lang="ar" dir="rtl"', false)->assertSee('تسجيل الدخول إلى مساحة العمل')->assertSee('English');
});

test('a user language choice persists and overrides the guest session', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);

    $this->actingAs($user)->withSession(['locale' => 'ar'])->get('/home')
        ->assertOk()->assertSee('lang="en" dir="ltr"', false);
    $this->post('/locale', ['locale' => 'ar'])->assertRedirect();
    expect($user->fresh()->locale)->toBe('ar');
    $this->get('/home')->assertOk()->assertSee('lang="ar" dir="rtl"', false)->assertSee('English');
});

test('locale switch validates supported languages', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/locale', ['locale' => 'ku'])->assertSessionHasErrors('locale');
    expect($user->fresh()->locale)->toBe('en');
});

test('UI numbers and dates follow the active locale in Baghdad time', function (): void {
    app()->setLocale('en');
    expect(Ui::number(123))->toBe('123');
    expect(Ui::date('2026-10-08 22:00:00 UTC'))->toBe('9 Oct 2026');

    app()->setLocale('ar');
    expect(Ui::number(123))->toBe('123');
    expect(Ui::date('2026-10-08 22:00:00 UTC'))->toBe('9 تشرين الأول 2026');
});

test('the admin panels use the single sign-in page, which offers the language switch', function (): void {
    $this->get('/admin/login')->assertRedirect('/login');
    $this->get('/platform/login')->assertRedirect('/login');
    $this->get('/admin')->assertRedirect('/login');
    $this->get('/platform')->assertRedirect('/login');

    $this->get('/login')->assertOk()->assertSee('العربية');
    $this->post('/locale', ['locale' => 'ar'])->assertRedirect();
    $this->get('/login')->assertOk()->assertSee('English');
});

test('an Arabic admin sees and can run a translated Filament action', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff, 'locale' => 'ar']);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    actingInAdminTenant($this, $user, $company);
    app()->setLocale('ar');

    $this->get(BranchResource::getUrl('index', panel: 'admin', tenant: $company))
        ->assertOk()->assertSee('تعطيل');
    Livewire::test(ListBranchs::class)->callTableAction('deactivate', $branch);
    expect($branch->fresh()->is_active)->toBeFalse();
});

test('both panels show the shared header language switch', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['primary_role' => UserRole::HrStaff, 'company_id' => $company->id, 'locale' => 'ar']);
    $this->actingAs($admin)->get('/admin/'.$company->id)->assertOk()
        ->assertSee(route('locale.update'), false)->assertSee('English');
    $platform = User::factory()->create(['primary_role' => UserRole::PlatformAdmin, 'company_id' => null, 'locale' => 'ar']);
    $this->actingAs($platform)->get('/platform')->assertOk()
        ->assertSee(route('locale.update'), false)->assertSee('English');
});
