<?php

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Models\User;

class InternalRegulationNav
{
    public static function url(?User $user): ?string
    {
        if (! $user?->is_active || $user->company_id === null
            || in_array($user->primary_role, [UserRole::Reporter, UserRole::CommitteeMember], true)
            || ($user->primary_role !== UserRole::CompanyAdmin && ! $user->can('review_internal_regulation'))) {
            return null;
        }

        return '/admin/'.$user->company_id.'/internal-regulation';
    }
}
