<?php

namespace App\Services\Employees;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use App\Support\Search\SearchText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmployeePicker
{
    public function authorizeBranch(User $actor, int $branchId): void
    {
        $this->scope($actor, $branchId);
    }

    private function scope(User $actor, int $branchId): Builder
    {
        $branch = Branch::query()->where('company_id', $actor->company_id)->where('is_active', true)->findOrFail($branchId);
        abort_unless($actor->is_active && $actor->company?->is_active, 403);
        if (in_array($actor->primary_role, [UserRole::Reporter, UserRole::DeptManager], true)) {
            abort_unless(DB::table('branch_user')->where('company_id', $actor->company_id)
                ->where('user_id', $actor->id)->where('branch_id', $branch->id)->exists(), 403);
        } else {
            abort_unless(in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true), 403);
        }

        return Employee::query()->where('company_id', $actor->company_id)->where('branch_id', $branchId)->where('is_active', true);
    }

    public function search(User $actor, int $branchId, string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        // search_name holds the normalised Arabic name, so a name typed without hamza still finds the employee.
        $normalized = SearchText::normalize($term);

        return $this->scope($actor, $branchId)->where(fn (Builder $query) => $query
            ->where('name', 'like', '%'.$term.'%')->orWhere('name_en', 'like', '%'.$term.'%')
            ->orWhere('search_name', 'like', '%'.$normalized.'%')
            ->orWhere('employee_code', 'like', '%'.$term.'%'))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'name_en', 'employee_code'])
            ->map(fn (Employee $employee) => ['id' => $employee->id, 'name' => $employee->name,
                'name_en' => $employee->name_en, 'code' => $employee->employee_code])->all();
    }

    public function employee(User $actor, int $branchId, int $id): Employee
    {
        return $this->scope($actor, $branchId)->findOrFail($id);
    }

    public function selections(User $actor, int $branchId, array $items, bool $allowFree): array
    {
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw ValidationException::withMessages(['people' => __('Choose a person or enter a name')]);
            }
            if (! empty($item['employee_id'])) {
                $employee = $this->employee($actor, $branchId, (int) $item['employee_id']);
                $result[] = ['employee_id' => $employee->id, 'name' => $employee->name];
            } elseif ($allowFree && is_string($item['name'] ?? null) && trim($item['name']) !== ''
                && mb_strlen(trim($item['name'])) <= 255) {
                $result[] = ['employee_id' => null, 'name' => trim($item['name'])];
            } else {
                throw ValidationException::withMessages(['people' => __('Choose a person or enter a name')]);
            }
        }

        return collect($result)->unique(fn (array $item) => $item['employee_id'] ?: mb_strtolower($item['name']))->values()->all();
    }
}
