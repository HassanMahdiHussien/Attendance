<?php

namespace App\Policies\Leave;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Leave\ImportBatch;
use App\Models\User;

class ImportBatchPolicy
{
    public function viewOriginal(User $user, ImportBatch $batch): bool
    {
        $company = Company::find($batch->company_id);

        return $user->is_active && in_array($user->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && $company !== null && $user->canAccessTenant($company);
    }
}
