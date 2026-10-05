<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Access\CaseVisibility;

class DisciplinaryCasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->company_id === null || $user->company?->is_active)
            && in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::CommitteeMember, UserRole::ExternalLegalReviewer], true);
    }

    public function view(User $user, DisciplinaryCase $case): bool
    {
        return $user->is_active && ($user->company_id === null || $user->company?->is_active)
            && CaseVisibility::for($user)->whereKey($case->getKey())->exists();
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->company?->is_active
            && in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true);
    }

    public function update(User $user, DisciplinaryCase $case): bool
    {
        return $this->create($user) && $this->view($user, $case);
    }

    public function manageDocuments(User $user, DisciplinaryCase $case): bool
    {
        return $this->update($user, $case);
    }

    public function confirm(User $user, DisciplinaryCase $case): bool
    {
        return $this->update($user, $case);
    }

    public function secondReview(User $user, DisciplinaryCase $case): bool
    {
        return $this->update($user, $case);
    }

    public function issue(User $user, DisciplinaryCase $case): bool
    {
        return $this->update($user, $case);
    }

    public function correct(User $user, DisciplinaryCase $case): bool
    {
        return $this->update($user, $case);
    }

    public function assign(User $user, DisciplinaryCase $case): bool
    {
        return $this->update($user, $case);
    }

    public function recordHandOff(User $user, DisciplinaryCase $case): bool
    {
        return $this->update($user, $case);
    }

    public function uploadAttachment(User $user, DisciplinaryCase $case): bool
    {
        return $this->update($user, $case);
    }
}
