<?php

use App\Enums\UserRole;
use App\Filament\Platform\Resources\CompanyResource;
use App\Filament\Platform\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Platform\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Platform\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Platform\Resources\HrPartnerResource;
use App\Filament\Platform\Resources\HrPartnerResource\Pages\ListHrPartners;
use App\Filament\Platform\Resources\LawFirmPartnerResource;
use App\Filament\Platform\Resources\LawFirmPartnerResource\Pages\ListLawFirmPartners;
use App\Filament\Platform\Resources\PlatformUserResource;
use App\Filament\Platform\Resources\PlatformUserResource\Pages\CreatePlatformUser;
use App\Filament\Platform\Resources\PlatformUserResource\Pages\ListPlatformUsers;
use App\Models\Company;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    app(TenantContext::class)->setAllCompanies();
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('platform resource matrix is available to platform admins in Livewire and at direct URLs', function (): void {
    $this->actingAs(User::factory()->create());
    foreach ([
        CompanyResource::class => ListCompanies::class,
        LawFirmPartnerResource::class => ListLawFirmPartners::class,
        HrPartnerResource::class => ListHrPartners::class,
        PlatformUserResource::class => ListPlatformUsers::class,
    ] as $resource => $component) {
        $this->get($resource::getUrl('index', panel: 'platform'))->assertOk();
        Livewire::test($component)->assertOk();
        $this->get($resource::getUrl('create', panel: 'platform'))->assertOk();
    }
});

test('non-platform actors cannot use platform resource URLs', function (string $role): void {
    $company = Company::factory()->create();
    $this->actingAs(User::factory()->create(['primary_role' => $role, 'company_id' => $company->id]));
    foreach ([CompanyResource::class, LawFirmPartnerResource::class, HrPartnerResource::class, PlatformUserResource::class] as $resource) {
        expect($this->get($resource::getUrl('index', panel: 'platform'))->status())->toBeIn([302, 403, 404]);
        expect($this->get($resource::getUrl('create', panel: 'platform'))->status())->toBeIn([302, 403, 404]);
    }
})->with([
    UserRole::CompanyAdmin->value,
    UserRole::HrStaff->value,
]);

test('company onboarding creates the first admin and keeps Arabic and English enabled', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);
    Livewire::test(CreateCompany::class)
        ->fillForm([
            'name' => 'شركة التجربة',
            'first_admin_name' => 'المدير',
            'first_admin_email' => 'first-admin@example.test',
            'first_admin_password' => 'secret12345',
            'subscription_status' => 'trial',
            'kurdish_enabled' => false,
        ])->call('create')->assertHasNoFormErrors();

    $company = Company::query()->where('name', 'شركة التجربة')->firstOrFail();
    expect($company->enabled_languages)->toBe(['ar', 'en']);
    expect(User::query()->where('company_id', $company->id)->where('primary_role', UserRole::CompanyAdmin->value)->count())->toBe(1);
    Livewire::test(ListCompanies::class)->callTableAction('toggleActive', $company);
    expect($company->fresh()->is_active)->toBeFalse();
});

test('partner actions write only through the partnership service', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $lawFirm = LawFirmPartner::factory()->create();
    $hrPartner = HrPartner::factory()->create();
    $this->actingAs($actor);

    Livewire::test(ListLawFirmPartners::class)
        ->callTableAction('attachCompany', $lawFirm, ['company_id' => $company->id]);
    $this->assertDatabaseHas('company_law_firm_partner', ['company_id' => $company->id, 'law_firm_partner_id' => $lawFirm->id]);

    Livewire::test(ListHrPartners::class)
        ->callTableAction('attachCompany', $hrPartner, ['company_id' => $company->id, 'full_case_access' => true]);
    $this->assertDatabaseHas('company_hr_partner', ['company_id' => $company->id, 'hr_partner_id' => $hrPartner->id, 'full_case_access' => true]);
});

test('company edit keeps required languages and saves optional settings', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $this->actingAs($actor);
    Livewire::test(EditCompany::class, ['record' => $company->id])->fillForm([
        'name' => 'شركة محدثة',
        'kurdish_enabled' => true,
        'requires_second_review' => true,
        'subscription_status' => 'active',
    ])->call('save')->assertHasNoFormErrors();
    expect($company->fresh()->enabled_languages)->toBe(['ar', 'en', 'ku']);
    expect($company->fresh()->requires_second_review)->toBeTrue();
});

test('external reviewer creation requires a law firm and platform deactivation takes effect', function (): void {
    $actor = User::factory()->create();
    $firm = LawFirmPartner::factory()->create();
    $this->actingAs($actor);

    Livewire::test(CreatePlatformUser::class)->fillForm([
        'name' => 'مراجع قانوني',
        'email' => 'reviewer@example.test',
        'password' => 'secret12345',
        'primary_role' => UserRole::ExternalLegalReviewer->value,
        'law_firm_partner_id' => $firm->id,
    ])->call('create')->assertHasNoFormErrors();
    $reviewer = User::query()->where('email', 'reviewer@example.test')->firstOrFail();
    expect($reviewer->company_id)->toBeNull();
    expect($reviewer->law_firm_partner_id)->toBe($firm->id);

    Livewire::test(ListPlatformUsers::class)->callTableAction('toggleActive', $reviewer);
    expect($reviewer->fresh()->is_active)->toBeFalse();
});
