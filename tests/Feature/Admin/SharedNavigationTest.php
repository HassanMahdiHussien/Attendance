<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Access\AdminNav;
use App\Support\Access\Nav;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;

test('settings links follow registered page and resource access for the current company', function (): void {
    $company = Company::factory()->create();

    $user = User::factory()->create(['primary_role' => UserRole::HrStaff, 'company_id' => $company->id]);
    $this->actingAs($user);
    // Real requests get this from the SetTenantContext middleware.
    app(TenantContext::class)->setCompany($company);

    $items = AdminNav::items($user);
    $labels = array_column($items, 'label');
    expect($labels)->toContain(__('Branches'));
    expect($labels)->toContain(__('Company settings'));
    foreach ($items as $item) {
        expect($item['url'])->toContain('/admin/'.$company->id.'/');
    }
    expect(array_column(Nav::items($user), 'label'))->not->toContain('Settings');
});

test('admin panel uses the shared workspace navigation and keeps settings access checks', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['primary_role' => UserRole::HrStaff, 'company_id' => $company->id]);
    $this->actingAs($user);
    app(TenantContext::class)->setCompany($company);
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setTenant($company, isQuiet: true);

    try {
        expect($panel->hasTopbar())->toBeFalse();
        expect($panel->hasNavigation())->toBeFalse();
        expect(array_column(AdminNav::items($user), 'label'))->toContain(__('Employee records'));
    } finally {
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);
    }
});
