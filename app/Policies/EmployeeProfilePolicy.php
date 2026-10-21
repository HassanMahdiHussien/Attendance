<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EmployeeProfilePolicy
{
    public function search(User $viewer): bool
    {
        return $viewer->is_active && in_array($viewer->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true);
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
            UserRole::HrStaff => (int) $viewer->company_id === $companyId,
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
            UserRole::HrStaff => $query->where('employees.company_id', $viewer->company_id),
            default => $query->whereRaw('1 = 0'),
        };
    }
}
