<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Support\Access\AdminNav;
use App\Support\Access\Nav;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;

test('settings links follow registered page and resource access for the current company', function (): void {
    $company = Company::factory()->create();

    foreach ([UserRole::CompanyAdmin, UserRole::HrStaff] as $role) {
        $user = User::factory()->create(['primary_role' => $role, 'company_id' => $company->id]);
        $this->actingAs($user);
        // Real requests get this from the SetTenantContext middleware.
        app(TenantContext::class)->setCompany($company);

        $items = AdminNav::items($user);
        $labels = array_column($items, 'label');
        expect($labels)->toContain(__('Document templates'));
        expect($labels)->toContain(__('Branches'));
        if ($role === UserRole::HrStaff) {
            // HR staff need the review permission for the regulation page (InternalRegulation::canAccess).
            expect($labels)->not->toContain(__('Company settings'));
            expect($labels)->not->toContain(__('Internal regulation'));
        } else {
            expect($labels)->toContain(__('Internal regulation'));
        }
        foreach ($items as $item) {
            expect($item['url'])->toContain('/admin/'.$company->id.'/');
        }
        expect(array_column(Nav::items($user), 'label'))->not->toContain('Settings');
        expect(array_column(Nav::items($user), 'label'))->not->toContain('Internal regulation');
    }
});

test('a company lawyer with the review permission is offered only the internal regulation page', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['primary_role' => UserRole::CommitteeMember, 'company_id' => $company->id]);
    Permission::findOrCreate('review_internal_regulation', 'web');
    $user->givePermissionTo('review_internal_regulation');
    $this->actingAs($user);
    app(TenantContext::class)->setCompany($company);

    expect(array_column(AdminNav::items($user), 'label'))->toBe([__('Internal regulation')]);
});

test('the same role without the review permission gets no admin panel', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['primary_role' => UserRole::CommitteeMember, 'company_id' => $company->id]);

    expect($user->canAccessPanel(Filament::getPanel('admin')))->toBeFalse()
        ->and(AdminNav::items($user))->toBe([]);
});

test('the company lawyer opens internal regulation but no other admin page', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $user = User::factory()->create(['primary_role' => UserRole::CommitteeMember, 'company_id' => $company->id]);
    Permission::findOrCreate('review_internal_regulation', 'web');
    $user->givePermissionTo('review_internal_regulation');
    $this->actingAs($user);

    $this->get('/admin/'.$company->id.'/internal-regulation')->assertOk();
    foreach (['branches', 'employees', 'users', 'locations', 'violation-types', 'company-settings', 'document-templates'] as $page) {
        expect($this->get('/admin/'.$company->id.'/'.$page)->status())->toBeIn([403, 404]);
    }
    expect($this->get('/admin/'.$other->id.'/internal-regulation')->status())->toBeIn([403, 404]);
});

test('admin navigation starts with translated workspace links and matches settings links', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['primary_role' => UserRole::CompanyAdmin, 'company_id' => $company->id]);
    $this->actingAs($user);
    app(TenantContext::class)->setCompany($company);
    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setTenant($company, isQuiet: true);

    try {
        foreach (['en' => 'Workspace', 'ar' => 'مساحة العمل'] as $locale => $heading) {
            app()->setLocale($locale);
            $groups = $panel->getNavigation();
            expect($groups[0]->getLabel())->toBe($heading);
            expect($groups[1]->getLabel())->toBe(__('Settings'));
            expect(count($groups[0]->getItems()))->toBe(count(Nav::items($user)));
            expect(count($groups[1]->getItems()))->toBe(count(AdminNav::items($user)));
        }
    } finally {
        Filament::setTenant(null, isQuiet: true);
        Filament::setCurrentPanel(null);
    }
});
