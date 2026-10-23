<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeProfile;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Builder;

class EmployeeFilterSearch
{
    public function __construct(private EmployeeProfile $profiles) {}

    public function find(User $viewer, Company $company, string $term): array
    {
        $term = SearchText::normalize(trim($term));
        if (mb_strlen($term) < 2) {
            return [];
        }
        $like = '%'.addcslashes($term, '%_\\').'%';
        $prefix = addcslashes($term, '%_\\').'%';

        return $this->profiles->search($viewer)->where('employees.company_id', $company->id)
            ->select(['employees.id', 'employees.name', 'employees.employee_code', 'profile_branch.name as branch_name'])
            ->where(fn (Builder $query) => $query->where('employees.search_name', 'like', $like)
                ->orWhere('employees.search_name_en', 'like', $like)
                ->orWhere('employees.employee_code', 'like', $prefix))
            ->limit(8)->get()->all();
    }

    public function selected(User $viewer, Company $company, int $employeeId): array
    {
        $employee = $this->profiles->search($viewer)->where('employees.company_id', $company->id)
            ->select(['employees.id', 'employees.name', 'employees.employee_code', 'profile_branch.name as branch_name'])
            ->whereKey($employeeId)->firstOrFail();

        return $this->item($employee);
    }

    private function item(Employee $employee): array
    {
        return ['id' => (int) $employee->id, 'name' => $employee->name,
            'code' => $employee->employee_code, 'branch' => $employee->branch_name];
    }
}
