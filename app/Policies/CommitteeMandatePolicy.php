<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\CommitteeMandate;
use App\Models\User;

class CommitteeMandatePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin, UserRole::HrStaff], true);
    }

    public function view(User $user, CommitteeMandate $mandate): bool
    {
        return $this->viewAny($user) && ($user->primary_role === UserRole::PlatformAdmin || $user->company_id === $mandate->company_id);
    }

    public function create(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin], true);
    }

    public function update(User $user, CommitteeMandate $mandate): bool
    {
        return $this->create($user) && $this->view($user, $mandate);
    }

    public function delete(User $user, CommitteeMandate $mandate): bool
    {
        return false;
    }
}
