<?php

namespace App\Livewire\Cases;

use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Livewire\Cases\Concerns\DraftsWithAi;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Services\Documents\DocumentDrafts;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class EditDocument extends Component
{
    use DraftsWithAi;

    public int $caseId;

    public ?int $documentId = null;

    public array $bodies = [];

    public string $expectedStatus = '';

    public string $notice = '';

    public function mount(string $case, ?string $document = null): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('manageDocuments', $record);
        $this->caseId = $record->id;
        $this->expectedStatus = $record->status->value;
        if ($document !== null && $document !== 'new') {
            $draft = $record->documents()->findOrFail($document);
            abort_if($draft->is_locked || $draft->document_type !== DocumentType::DefenseLetter, 404);
            $this->documentId = $draft->id;
            $this->bodies = $draft->contents->pluck('body', 'language_code')->all();
        }
        foreach (array_unique(array_merge(['ar', 'en'], $record->company->enabled_languages)) as $language) {
            $this->bodies[$language] ??= '';
        }
    }

    public function save(DocumentDrafts $drafts): void
    {
        $case = $this->currentCase();
        $this->validate(['bodies.*' => ['required', 'string', 'max:50000']]);
        $document = $drafts->createOrUpdate($case, DocumentType::DefenseLetter, $this->bodies,
            auth()->user(), $this->documentId ? $case->documents()->findOrFail($this->documentId) : null);
        $this->documentId = $document->id;
        $this->expectedStatus = $case->fresh()->status->value;
        $this->notice = __('messages.document.draft_saved');
    }

    public function submit(DocumentDrafts $drafts): void
    {
        $this->save($drafts);
        try {
            $case = $this->currentCase();
            $drafts->submit($case->documents()->findOrFail($this->documentId), auth()->user());
        } catch (IssuanceBlocked|TransitionBlocked $error) {
            $this->expectedStatus = CaseVisibility::for(auth()->user())->findOrFail($this->caseId)->status->value;
            $this->notice = $error->getMessage();

            return;
        }
        $this->redirect('/cases/'.$this->caseId.'/documents/'.$this->documentId.'/confirm', navigate: true);
    }

    public function back(DocumentDrafts $drafts): void
    {
        $case = $this->currentCase();
        $drafts->backToEdit($case->documents()->findOrFail($this->documentId), auth()->user());
        $this->expectedStatus = $case->fresh()->status->value;
        $this->notice = __('messages.document.returned_to_editing');
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('manageDocuments', $case);

        $document = $this->documentId ? $case->documents()->findOrFail($this->documentId) : null;

        return view('livewire.cases.edit-document', ['record' => $case, 'document' => $document,
            'aiAvailable' => $this->canRequestAi($case), 'aiBusy' => $case->status === $this->aiBusyStage() && $case->ai_request_id !== null,
            'aiFailed' => $this->aiFailed($case),
            'units' => $this->citableUnits($case), 'languages' => array_unique(array_merge(['ar', 'en'], $case->company->enabled_languages))])
            ->layout('layouts.workspace');
    }

    protected function aiDocumentType(): DocumentType
    {
        return DocumentType::DefenseLetter;
    }

    protected function loadAiBodies(Document $document): void
    {
        $this->bodies = $document->contents->pluck('body', 'language_code')->all();
    }

    private function currentCase(): DisciplinaryCase
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('manageDocuments', $case);
        if ($case->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => __('messages.case.changed_reload')]);
        }

        return $case;
    }
}
