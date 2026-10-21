<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\CompanySettings;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\BranchResource\Pages\CreateBranch;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Admin\Resources\LocationResource;
use App\Filament\Admin\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Models\Company;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Livewire\Livewire;

test('admin create permissions agree on Livewire components and direct URLs for every setup resource', function (): void {
    $company = Company::factory()->create();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $resources = [
        [BranchResource::class, CreateBranch::class],
        [LocationResource::class, CreateLocation::class],
        [EmployeeResource::class, CreateEmployee::class],
        [UserResource::class, CreateUser::class],
    ];

    foreach ([UserRole::HrStaff, UserRole::PlatformAdmin] as $role) {
        $actor = User::factory()->create([
            'primary_role' => $role,
            'company_id' => $role === UserRole::PlatformAdmin ? null : $company->id,
        ]);
        $this->actingAs($actor);
        Filament::setTenant($company);

        foreach ($resources as [$resource, $page]) {
            app(TenantContext::class)->setCompany($company);
            Livewire::test($page)->assertStatus(200);
            $url = $resource::getUrl('create', panel: 'admin', tenant: $company);
            expect($this->get($url)->status())->toBe(200);
        }

        app(TenantContext::class)->setCompany($company);
        Livewire::test(CompanySettings::class)->assertStatus(200);
        $settingsUrl = CompanySettings::getUrl(panel: 'admin', tenant: $company);
        expect($this->get($settingsUrl)->status())->toBe(200);
    }

    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});
