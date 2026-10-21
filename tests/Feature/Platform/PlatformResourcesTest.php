<?php

use App\Enums\UserRole;
use App\Filament\Platform\Resources\CompanyResource;
use App\Filament\Platform\Resources\CompanyResource\Pages\CreateCompany;
use App\Filament\Platform\Resources\CompanyResource\Pages\EditCompany;
use App\Filament\Platform\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Platform\Resources\PlatformUserResource;
use App\Filament\Platform\Resources\PlatformUserResource\Pages\ListPlatformUsers;
use App\Models\Company;
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
    foreach ([CompanyResource::class, PlatformUserResource::class] as $resource) {
        expect($this->get($resource::getUrl('index', panel: 'platform'))->status())->toBeIn([302, 403, 404]);
        expect($this->get($resource::getUrl('create', panel: 'platform'))->status())->toBeIn([302, 403, 404]);
    }
})->with([UserRole::HrStaff->value]);

test('company onboarding creates the first HR user and keeps Arabic and English enabled', function (): void {
    $actor = User::factory()->create();
    $this->actingAs($actor);
    Livewire::test(CreateCompany::class)
        ->fillForm([
            'name' => 'شركة التجربة',
            'first_hr_user_name' => 'المدير',
            'first_hr_user_email' => 'first-hr@example.test',
            'first_hr_user_password' => 'secret12345',
            'subscription_status' => 'trial',
            'kurdish_enabled' => false,
        ])->call('create')->assertHasNoFormErrors();

    $company = Company::query()->where('name', 'شركة التجربة')->firstOrFail();
    expect($company->enabled_languages)->toBe(['ar', 'en']);
    expect(User::query()->where('company_id', $company->id)->where('primary_role', UserRole::HrStaff->value)->count())->toBe(1);
    Livewire::test(ListCompanies::class)->callTableAction('toggleActive', $company);
    expect($company->fresh()->is_active)->toBeFalse();
});

test('company edit keeps required languages and saves optional settings', function (): void {
    $actor = User::factory()->create();
    $company = Company::factory()->create();
    $this->actingAs($actor);
    Livewire::test(EditCompany::class, ['record' => $company->id])->fillForm([
        'name' => 'شركة محدثة',
        'kurdish_enabled' => true,
        'subscription_status' => 'active',
    ])->call('save')->assertHasNoFormErrors();
    expect($company->fresh()->enabled_languages)->toBe(['ar', 'en', 'ku']);
});
