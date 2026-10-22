<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Policies\EmployeeProfilePolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

class EmployeeProfile
{
    public function __construct(private EmployeeProfilePolicy $policy) {}

    public function search(User $viewer, string $term = ''): Builder
    {
        abort_unless($this->policy->search($viewer), 403);
        $term = trim($term);

        return $this->policy->scope($viewer)->leftJoin('branches as profile_branch', fn ($join) => $join
            ->on('profile_branch.id', '=', 'employees.branch_id')->on('profile_branch.company_id', '=', 'employees.company_id'))
            ->select('employees.*', 'profile_branch.name as branch_name')
            ->when($term !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('employees.name', 'like', '%'.$term.'%')->orWhere('employees.employee_code', 'like', '%'.$term.'%')
                ->orWhere('employees.department', 'like', '%'.$term.'%')))->orderBy('employees.name');
    }

    public function build(User $viewer, Employee $employee): array
    {
        return app(TenantContext::class)->runForCompany($employee->company_id, function () use ($viewer, $employee): array {
            abort_unless($this->policy->view($viewer, $employee), 404);

            return [
                'employee' => $employee->loadMissing(['branch', 'company']),
                'changes' => $employee->versions()->with('actor')->orderByDesc('effective_from')->orderByDesc('id')->limit(10)->get(),
            ];
        });
    }
}
