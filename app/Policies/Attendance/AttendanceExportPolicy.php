<?php

namespace App\Policies\Attendance;

use App\Enums\UserRole;
use App\Models\Attendance\AttendanceExport;
use App\Models\Company;
use App\Models\User;

class AttendanceExportPolicy
{
    public function create(User $user, Company $company): bool
    {
        return $user->is_active && in_array($user->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && $user->canAccessTenant($company);
    }

    public function view(User $user, AttendanceExport $export): bool
    {
        $company = Company::find($export->company_id);

        return $company !== null && $this->create($user, $company);
    }

    public function retry(User $user, AttendanceExport $export): bool
    {
        return $this->view($user, $export);
    }
}
