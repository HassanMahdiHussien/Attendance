<?php

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Models\User;

class Landing
{
    public static function for(User $user): string
    {
        return match ($user->primary_role) {
            UserRole::PlatformAdmin => '/platform',
            UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::Reporter, UserRole::DeptManager,
            UserRole::CommitteeMember, UserRole::ExternalLegalReviewer, UserRole::HrPartnerStaff => '/home',
        };
    }
}
