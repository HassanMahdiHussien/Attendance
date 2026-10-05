<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CommitteePoolMember;
use App\Models\User;

class CommitteePoolMemberPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin, UserRole::HrStaff], true);
    }

    public function view(User $user, CommitteePoolMember $member): bool
    {
        return $this->viewAny($user) && ($user->primary_role === UserRole::PlatformAdmin || $user->company_id === $member->company_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin], true);
    }

    public function update(User $user, CommitteePoolMember $member): bool
    {
        return $this->create($user) && $this->view($user, $member);
    }

    public function delete(User $user, CommitteePoolMember $member): bool
    {
        return false;
    }
}
