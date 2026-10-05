<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\HrPartner;
use App\Models\User;

class HrPartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->primary_role === UserRole::PlatformAdmin;
    }

    public function view(User $user, HrPartner $partner): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, HrPartner $partner): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, HrPartner $partner): bool
    {
        return false;
    }
}
