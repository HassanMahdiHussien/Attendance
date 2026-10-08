<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Services\Employees\EmployeeProfile;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class EmployeePage extends Component
{
    public int $employeeId;

    public function mount(string $employee): void
    {
        $this->employeeId = (int) $employee;
    }

    public function render(EmployeeProfile $profiles): View
    {
        $employee = Employee::withoutGlobalScopes()->findOrFail($this->employeeId);

        return view('livewire.employees.employee-page', $profiles->build(auth()->user(), $employee))
            ->layout('layouts.workspace');
    }
}
