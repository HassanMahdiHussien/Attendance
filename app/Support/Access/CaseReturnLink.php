<?php

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Models\User;

class CaseReturnLink
{
    public static function for(User $user): ?string
    {
        return match ($user->primary_role) {
            UserRole::CompanyAdmin, UserRole::HrStaff => '/cases',
            UserRole::CommitteeMember, UserRole::ExternalLegalReviewer => '/my-cases',
            default => null,
        };
    }
}
