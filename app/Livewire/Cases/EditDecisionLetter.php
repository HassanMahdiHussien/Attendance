<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Services\Cases\TerminationLeaveConfirmation;
use App\Services\Documents\DocumentDrafts;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class EditDecisionLetter extends Component
{
    public int $caseId;

    public ?int $documentId = null;

    public string $expectedStatus = '';

    public array $reasons = [];

    public string $notice = '';

    public bool $leaveChecked = false;

    public function mount(string $case): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('manageDocuments', $record);
        abort_unless(in_array($record->status, [CaseStatus::SignedRecommendationsUploaded, CaseStatus::DecisionLetterCitationCheck], true)
            && in_array($record->outcome?->value, ['warning', 'termination'], true), 404);
        $this->caseId = $record->id;
        $this->expectedStatus = $record->status->value;
        $draft = $record->documents()->where('document_type', DocumentType::DecisionLetter->value)->where('is_locked', false)->first();
        if ($draft) {
            $this->documentId = $draft->id;
            $this->reasons = $draft->contents->pluck('body', 'language_code')->all();
        }
    }

    public function save(DocumentDrafts $drafts): void
    {
        $case = $this->editable();
        $document = $drafts->createOrUpdateDecision($case, $this->reasons, auth()->user(),
            $this->documentId ? $case->documents()->findOrFail($this->documentId) : null);
        $this->documentId = $document->id;
        $this->notice = 'حُفظت مسودة القرار.';
    }

    public function confirmLeave(TerminationLeaveConfirmation $confirmations): void
    {
        $case = $this->editable();
        $this->validate(['leaveChecked' => ['accepted']]);
        $this->save(app(DocumentDrafts::class));
        $confirmations->confirm($case->documents()->findOrFail($this->documentId), auth()->user());
        $this->notice = 'سُجل تأكيد عدم وجود إجازة قانونية أو غياب مرضي موثق.';
    }

    public function submit(DocumentDrafts $drafts): void
    {
        $this->save($drafts);
        try {
            $drafts->submitDecision(Document::query()->findOrFail($this->documentId), auth()->user());
            $this->redirect('/cases/'.$this->caseId.'/documents/'.$this->documentId.'/confirm', navigate: true);
        } catch (TransitionBlocked|IssuanceBlocked $error) {
            $this->expectedStatus = CaseVisibility::for(auth()->user())->findOrFail($this->caseId)->status->value;
            $this->notice = $error->getMessage();
        }
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('manageDocuments', $case);

        return view('livewire.cases.edit-decision-letter', ['case' => $case,
            'languages' => array_unique(array_merge(['ar', 'en'], $case->company->enabled_languages)),
            'document' => $this->documentId ? $case->documents()->findOrFail($this->documentId) : null])->layout('layouts.workspace');
    }

    private function editable(): DisciplinaryCase
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('manageDocuments', $case);
        if ($case->status->value !== $this->expectedStatus || ! in_array($case->status,
            [CaseStatus::SignedRecommendationsUploaded, CaseStatus::DecisionLetterCitationCheck], true)) {
            throw ValidationException::withMessages(['notice' => 'تغيرت الحالة، أعد التحميل']);
        }

        return $case;
    }
}
