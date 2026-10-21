<?php

namespace App\Services\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Models\ViolationReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReportEmployeeQuickAdd
{
    public function create(ViolationReport $report, User $actor, string $name, string $position, string $department, string $hireDate): Employee
    {
        Gate::forUser($actor)->authorize('create', Employee::class);
        abort_unless((int) $report->company_id === (int) $actor->company_id, 403);
        app(EmployeePicker::class)->authorizeBranch($actor, (int) $report->branch_id);

        return DB::transaction(fn (): Employee => Employee::create([
            'company_id' => $actor->company_id,
            'branch_id' => $report->branch_id,
            'name' => $name,
            'position' => $position,
            'department' => $department,
            'hire_date' => $hireDate,
        ]));
    }
}
