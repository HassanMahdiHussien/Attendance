<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\EmployeeImportBatch;
use App\Models\User;

class EmployeeImportBatchPolicy
{
    public function viewOriginal(User $user, EmployeeImportBatch $batch): bool
    {
        return $user->is_active && ($user->primary_role === UserRole::PlatformAdmin
            || ($user->primary_role === UserRole::HrStaff && $user->company_id === $batch->company_id));
    }
}
