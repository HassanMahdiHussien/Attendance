<?php

namespace App\Support\Access;

use App\Enums\UserRole;
use App\Models\User;

class DashboardNav
{
    public static function visible(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true);
    }
}
