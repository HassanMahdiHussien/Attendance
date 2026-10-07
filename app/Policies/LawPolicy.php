<?php

namespace App\Policies;

use App\Enums\LawKind;
use App\Enums\UserRole;
use App\Models\Law;
use App\Models\User;

class LawPolicy
{
    public function import(User $user, Law $law): bool
    {
        if (! $user->is_active || ($user->company_id !== null && ! $user->company?->is_active)) {
            return false;
        }
        if ($law->kind === LawKind::Statute) {
            return $user->primary_role === UserRole::PlatformAdmin;
        }

        return $user->primary_role === UserRole::PlatformAdmin
            || ($user->primary_role === UserRole::CompanyAdmin && (int) $user->company_id === (int) $law->company_id);
    }

    public function review(User $user, Law $law): bool
    {
        return $user->is_active && ($user->company_id === null || $user->company?->is_active) && ($law->kind === LawKind::Statute
            ? $user->primary_role === UserRole::PlatformAdmin
            : (int) $user->company_id === (int) $law->company_id && $user->can('review_internal_regulation'));
    }

    public function viewPage(User $user, Law $law): bool
    {
        if (! $user->is_active || ($user->company_id !== null && ! $user->company?->is_active)) {
            return false;
        }
        if ($user->primary_role === UserRole::PlatformAdmin) {
            return true;
        }
        if ($law->kind === LawKind::Statute) {
            return $user->company_id !== null && $user->primary_role !== UserRole::Reporter;
        }

        return (int) $user->company_id === (int) $law->company_id
            && (in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || $user->can('review_internal_regulation'));
    }
}
