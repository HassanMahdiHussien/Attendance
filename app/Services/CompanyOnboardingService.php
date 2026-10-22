<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Attendance\OfficePolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class CompanyOnboardingService
{
    public function create(array $company, array $firstHrUser, User $actor): Company
    {
        abort_unless($actor->primary_role === UserRole::PlatformAdmin, 403);

        return DB::transaction(function () use ($company, $firstHrUser, $actor): Company {
            $tenant = Company::create($company + ['enabled_languages' => ['ar', 'en']]);
            $user = new User;
            $user->fill(Arr::only($firstHrUser, ['name', 'email', 'password']));
            $user->forceFill(['company_id' => $tenant->id, 'primary_role' => UserRole::HrStaff]);
            $user->save();
            app(TenantContext::class)->runForCompany($tenant, fn () => app(OfficePolicy::class)->createDefault($tenant, $actor));

            return $tenant;
        });
    }
}
