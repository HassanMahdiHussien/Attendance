<?php

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;

class EmployeeActions
{
    public static function for(User $user, Collection $employees, string $companyId = ''): array
    {
        $panel = Filament::getPanel('admin');
        if (! $user->canAccessPanel($panel)) {
            return ['addUrl' => null, 'importUrl' => null, 'editUrls' => [], 'companyChoices' => []];
        }

        $companyChoices = $user->primary_role === UserRole::PlatformAdmin
            ? Company::query()->orderBy('name')->pluck('name', 'id')->all() : [];
        $employeeCompanies = $user->primary_role === UserRole::PlatformAdmin
            ? Company::query()->whereIn('id', $employees->pluck('company_id'))->get()->keyBy('id') : collect();
        $tenant = $user->primary_role === UserRole::PlatformAdmin
            ? ($companyId !== '' ? Company::query()->find($companyId) : null) : $user->company;
        $previousPanel = Filament::getCurrentPanel();
        $previousTenant = Filament::getTenant();
        Filament::setCurrentPanel($panel);
        Filament::setTenant($tenant, isQuiet: true);

        try {
            return [
                'companyChoices' => $companyChoices,
                'addUrl' => $tenant && $user->canAccessTenant($tenant) && $user->can('create', Employee::class)
                    ? EmployeeResource::getUrl('create', panel: 'admin', tenant: $tenant) : null,
                'importUrl' => $tenant && $user->can('importEmployees', $tenant)
                    ? ImportEmployees::getUrl(panel: 'admin', tenant: $tenant) : null,
                'editUrls' => $employees->filter(fn (Employee $employee): bool => $user->can('update', $employee))
                    ->mapWithKeys(function (Employee $employee) use ($user, $employeeCompanies): array {
                        $company = $user->primary_role === UserRole::PlatformAdmin
                            ? $employeeCompanies->get($employee->company_id) : $user->company;

                        return $company && $user->canAccessTenant($company)
                            ? [$employee->id => EmployeeResource::getUrl('edit', ['record' => $employee], panel: 'admin', tenant: $company)] : [];
                    })->all(),
            ];
        } finally {
            Filament::setTenant($previousTenant, isQuiet: true);
            Filament::setCurrentPanel($previousPanel);
        }
    }
}
