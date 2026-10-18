<?php

namespace App\Livewire\Employees;

use App\Services\Employees\EmployeeProfile;
use App\Support\Access\EmployeeActions;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class EmployeeIndex extends Component
{
    use WithPagination;

    public string $search = '';

    #[Url]
    public string $companyId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(EmployeeProfile $profiles): View
    {
        $user = auth()->user();
        $employees = $profiles->search($user, $this->search)->paginate(25);

        return view('livewire.employees.employee-index', [
            'employees' => $employees,
            ...EmployeeActions::for($user, $employees->getCollection(), $this->companyId),
        ])->layout('layouts.workspace');
    }
}
