<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin, UserRole::HrStaff], true);
    }

    public function view(User $actor, User $subject): bool
    {
        return $actor->primary_role === UserRole::PlatformAdmin || ($actor->company_id !== null && $actor->company_id === $subject->company_id
            && ($actor->primary_role === UserRole::CompanyAdmin || ($actor->primary_role === UserRole::HrStaff && in_array($subject->primary_role, [UserRole::Reporter, UserRole::DeptManager], true))));
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $actor, User $subject): bool
    {
        return $this->view($actor, $subject);
    }

    public function delete(User $actor, User $subject): bool
    {
        return false;
    }
}
