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
            UserRole::CompanyAdmin, UserRole::HrStaff => '/cases',
            UserRole::Reporter, UserRole::DeptManager => '/reports',
            UserRole::CommitteeMember, UserRole::ExternalLegalReviewer => '/my-cases',
            UserRole::HrPartnerStaff => '/home',
        };
    }
}
