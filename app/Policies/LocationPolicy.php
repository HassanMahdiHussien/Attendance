<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::HrStaff], true);
    }

    public function view(User $user, Location $location): bool
    {
        return $this->viewAny($user) && ($user->primary_role === UserRole::PlatformAdmin || $user->company_id === $location->company_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Location $location): bool
    {
        return $this->view($user, $location);
    }

    public function delete(User $user, Location $location): bool
    {
        return false;
    }
}
