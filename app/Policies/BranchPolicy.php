<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::HrStaff], true);
    }

    public function view(User $user, Branch $branch): bool
    {
        return $this->viewAny($user) && ($user->primary_role === UserRole::PlatformAdmin || $user->company_id === $branch->company_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::HrStaff], true);
    }

    public function update(User $user, Branch $branch): bool
    {
        return $this->create($user) && $this->view($user, $branch);
    }

    public function delete(User $user, Branch $branch): bool
    {
        return false;
    }
}
