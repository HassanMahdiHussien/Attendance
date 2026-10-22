<?php

namespace App\Services\Search;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\EmployeeProfilePolicy;
use App\Support\Search\SearchText;

class GlobalSearch
{
    public function allowed(User $viewer): bool
    {
        return $viewer->is_active
            && in_array($viewer->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && ($viewer->company_id === null || $viewer->company?->is_active)
            && app(EmployeeProfilePolicy::class)->search($viewer);
    }

    public function for(User $viewer, string $query): array
    {
        $groups = ['employees' => []];
        $term = SearchText::normalize($query);
        if (! $this->allowed($viewer) || mb_strlen($term) < 2) {
            return $groups;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $prefix = addcslashes($term, '%_\\').'%';
        $number = ctype_digit($term) ? (int) $term : null;
        $employees = app(EmployeeProfilePolicy::class)->scope($viewer)
            ->where(function ($scope) use ($like, $prefix, $number): void {
                $scope->where('employees.search_name', 'like', $like)
                    ->orWhere('employees.employee_code', 'like', $prefix);
                if ($number !== null) {
                    $scope->orWhere('employees.employee_code', 'like', 'EMP-'.$prefix);
                }
            })
            ->orderByRaw('CASE WHEN employees.employee_code IN (?, ?) THEN 0 ELSE 1 END', [$term, 'EMP-'.$term])
            ->orderBy('employees.name')->limit(8)->get();
        $groups['employees'] = $employees->map(fn ($employee) => [
            'label' => $employee->name, 'detail' => $employee->employee_code.' · '.$employee->department,
            'url' => route('employees.show', $employee),
        ])->all();

        return $groups;
    }
}
