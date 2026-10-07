<?php

namespace App\Livewire\Employees;

use App\Services\Employees\EmployeeProfile;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class EmployeeIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(EmployeeProfile $profiles): View
    {
        return view('livewire.employees.employee-index', [
            'employees' => $profiles->search(auth()->user(), $this->search)->paginate(25),
        ])->layout('layouts.workspace');
    }
}
