<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class EmployeeProfilePolicy
{
    public function search(User $viewer): bool
    {
        return $viewer->is_active && in_array($viewer->primary_role, [UserRole::CompanyAdmin,
            UserRole::HrStaff, UserRole::PlatformAdmin, UserRole::HrPartnerStaff], true);
    }

    public function view(User $viewer, Employee $employee): bool
    {
        return $this->search($viewer) && $this->companyAllowed($viewer, (int) $employee->company_id);
    }

    public function companyAllowed(User $viewer, int $companyId): bool
    {
        if (! $this->search($viewer)) {
            return false;
        }

        return match ($viewer->primary_role) {
            UserRole::PlatformAdmin => true,
            UserRole::CompanyAdmin, UserRole::HrStaff => (int) $viewer->company_id === $companyId,
            UserRole::HrPartnerStaff => DB::table('company_hr_partner')->where('company_id', $companyId)
                ->where('hr_partner_id', $viewer->hr_partner_id)->where('full_case_access', true)->exists(),
            default => false,
        };
    }

    public function scope(User $viewer): Builder
    {
        $query = Employee::query();
        if (! $this->search($viewer)) {
            return $query->whereRaw('1 = 0');
        }

        return match ($viewer->primary_role) {
            UserRole::PlatformAdmin => $query->withoutGlobalScopes(),
            UserRole::CompanyAdmin, UserRole::HrStaff => $query->where('employees.company_id', $viewer->company_id),
            UserRole::HrPartnerStaff => $query->withoutGlobalScopes()->whereIn('employees.company_id',
                DB::table('company_hr_partner')->select('company_id')->where('hr_partner_id', $viewer->hr_partner_id)
                    ->where('full_case_access', true)),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
