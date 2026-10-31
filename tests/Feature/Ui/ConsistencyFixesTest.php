<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Access\AdminNav;
use App\Support\Access\Nav;
use App\Support\Access\PlatformNav;
use App\Support\Access\WorkspaceTenants;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;

test('workspace and Filament panels render the same header and phone navigation', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($admin);
    app(TenantContext::class)->setCompany($company);

    $workspace = $this->get('/employees')->assertOk()->getContent();
    $panel = $this->get('/admin/'.$company->id)->assertOk()->getContent();
    foreach ([$workspace, $panel] as $html) {
        expect($html)->toContain('id="workspace-account"', 'id="workspace-more"', 'id="workspace-settings"');
        expect(substr_count($html, '<header class="sticky top-0 z-50 bg-night text-white">'))->toBe(1);
        expect(substr_count($html, 'id="workspace-account"'))->toBe(1);
    }
    expect(Filament::getPanel('admin')->hasTopbar())->toBeFalse();

    $platformUser = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    Company::factory()->create();
    $html = $this->actingAs($platformUser)->get('/platform')->assertOk()->getContent();
    expect($html)->toContain('id="workspace-account"', 'id="workspace-more"', 'id="workspace-platform"');
    expect(PlatformNav::items($platformUser))->toHaveCount(3);
    expect(WorkspaceTenants::items($platformUser))->toHaveCount(2);
    $platformWorkspace = $this->get('/employees')->assertOk()->getContent();
    expect($platformWorkspace)->toContain('id="workspace-account"', 'id="workspace-more"', 'id="workspace-platform"');
    expect(substr_count($html, '<header class="sticky top-0 z-50 bg-night text-white">'))->toBe(1);
    expect(substr_count($platformWorkspace, '<header class="sticky top-0 z-50 bg-night text-white">'))->toBe(1);
    expect(Filament::getPanel('platform')->hasTopbar())->toBeFalse();
    $theme = File::get(resource_path('css/filament/theme.css'));
    expect($theme)->toContain("@source '../../views/layouts/partials/workspace-navigation.blade.php'", '--color-night:', '--color-signal:');
});

test('employee management actions retain their policy checks', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($admin);

    expect(array_column(Nav::items($admin), 'label'))->toContain('Employees');
    expect(array_column(AdminNav::items($admin), 'label'))->toContain(__('Employee records'));
    $this->get('/employees')->assertOk()->assertSee(__('Add employee'))->assertSee(__('Import CSV'))
        ->assertSee(__('Edit'))->assertSee('/admin/'.$company->id.'/employees/', false);
    $this->get('/admin/'.$company->id.'/employees')->assertOk();
});

test('Arabic Filament component overrides include select and badge actions', function (): void {
    expect(trans('filament-forms::components.select.actions.clear.label', [], 'ar'))->toBe('مسح الاختيار');
    expect(trans('filament-forms::components.select.actions.remove_option.label', [], 'ar'))->toBe('إزالة :label');
    expect(trans('filament-forms::components.select.search_label', [], 'ar'))->toBe('بحث');
    expect(trans('filament::components/badge.actions.delete.label', [], 'ar'))->toBe('إزالة');
});
