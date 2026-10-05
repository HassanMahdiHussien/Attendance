<?php

namespace App\Livewire\Cases;

use App\Models\Employee;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class ShowCase extends Component
{
    public int $caseId;

    public function mount(string $case): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('view', $record);
        $this->caseId = $record->id;
    }

    public function render(): View
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('view', $record);
        $record->setRelation('employee', Employee::withoutGlobalScopes()->find($record->employee_id));

        return view('livewire.cases.show-case', ['record' => $record])->layout('layouts.workspace');
    }
}
