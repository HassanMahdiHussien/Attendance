<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LawFirmPartner;
use App\Models\User;

class LawFirmPartnerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->primary_role === UserRole::PlatformAdmin;
    }

    public function view(User $user, LawFirmPartner $partner): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, LawFirmPartner $partner): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, LawFirmPartner $partner): bool
    {
        return false;
    }
}
