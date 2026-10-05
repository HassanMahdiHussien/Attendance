<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->primary_role, [UserRole::PlatformAdmin, UserRole::CompanyAdmin, UserRole::HrStaff], true);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $this->viewAny($user) && ($user->primary_role === UserRole::PlatformAdmin || $user->company_id === $employee->company_id);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $this->view($user, $employee);
    }

    public function delete(User $user, Employee $employee): bool
    {
        return false;
    }
}
