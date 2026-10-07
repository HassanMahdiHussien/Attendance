<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Models\AppealTracking;
use App\Models\DisciplinaryCase;
use App\Services\Cases\AppealService;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class RecordAppeal extends Component
{
    public int $caseId;

    public string $expectedStatus = '';

    public string $filedAt = '';

    public string $outcome = '';

    public function mount(string $case): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('update', $record);
        abort_unless(in_array($record->status, [CaseStatus::AwaitingAppealWindow, CaseStatus::AppealPending], true), 404);
        $this->caseId = $record->id;
        $this->expectedStatus = $record->status->value;
    }

    public function file(AppealService $appeals): void
    {
        $case = $this->editable();
        $this->validate(['filedAt' => ['required', 'date']]);
        $appeals->logFiled($case, auth()->user(), $this->filedAt);
        $this->expectedStatus = CaseStatus::AppealPending->value;
        session()->flash('case_success_'.$this->caseId, 'سُجل تقديم الطعن.');
        $this->redirect('/cases/'.$this->caseId, navigate: true);
    }

    public function resolve(AppealService $appeals): void
    {
        $case = $this->editable();
        $this->validate(['outcome' => ['required', 'string', 'max:50000']]);
        $appeals->logOutcome($case, auth()->user(), $this->outcome);
        session()->flash('case_success_'.$this->caseId, 'سُجلت نتيجة الطعن وأُرشفت الحالة.');
        $this->redirect('/cases/'.$this->caseId, navigate: true);
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('update', $case);

        return view('livewire.cases.record-appeal', ['case' => $case,
            'tracking' => AppealTracking::query()->where('case_id', $case->id)->first()])->layout('layouts.workspace');
    }

    private function editable(): DisciplinaryCase
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('update', $case);
        if ($case->status->value !== $this->expectedStatus || ! in_array($case->status,
            [CaseStatus::AwaitingAppealWindow, CaseStatus::AppealPending], true)) {
            throw ValidationException::withMessages(['notice' => 'تغيرت الحالة، أعد التحميل']);
        }

        return $case;
    }
}
