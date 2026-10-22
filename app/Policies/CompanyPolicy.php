<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->primary_role === UserRole::PlatformAdmin;
    }

    public function view(User $user, Company $company): bool
    {
        return $this->viewAny($user) || ($user->company_id === $company->id && $user->primary_role === UserRole::HrStaff);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->viewAny($user) || ($user->primary_role === UserRole::HrStaff && $user->company_id === $company->id);
    }

    public function delete(User $user, Company $company): bool
    {
        return false;
    }
}
