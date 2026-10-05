<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CompanyOnboardingService
{
    public function create(array $company, array $admin, User $actor): Company
    {
        abort_unless($actor->primary_role === UserRole::PlatformAdmin, 403);

        return DB::transaction(function () use ($company, $admin): Company {
            $tenant = Company::create($company + ['enabled_languages' => ['ar', 'en']]);
            $firstAdmin = new User;
            $firstAdmin->fill(Arr::only($admin, ['name', 'email', 'password']));
            $firstAdmin->forceFill(['company_id' => $tenant->id, 'primary_role' => UserRole::CompanyAdmin]);
            $firstAdmin->save();

            return $tenant;
        });
    }
}
