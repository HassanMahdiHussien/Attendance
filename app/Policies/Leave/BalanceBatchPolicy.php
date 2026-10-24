<?php

namespace App\Policies\Leave;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Leave\BalanceBatch;
use App\Models\User;

class BalanceBatchPolicy
{
    public function viewOriginal(User $user, BalanceBatch $batch): bool
    {
        $company = Company::find($batch->company_id);

        return $user->is_active && in_array($user->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && $company !== null && $user->canAccessTenant($company);
    }
}
