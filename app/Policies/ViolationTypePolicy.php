<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\ViolationType;

class ViolationTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin, UserRole::HrStaff], true);
    }

    public function view(User $user, ViolationType $type): bool
    {
        return $this->viewAny($user) && ($user->primary_role === UserRole::PlatformAdmin || $user->company_id === $type->company_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, ViolationType $type): bool
    {
        return $this->view($user, $type);
    }

    public function delete(User $user, ViolationType $type): bool
    {
        return false;
    }
}
