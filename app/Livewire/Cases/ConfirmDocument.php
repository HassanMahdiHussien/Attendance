<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Models\Document;
use App\Services\Cases\DefenseLetterFlow;
use App\Services\Documents\DocumentDrafts;
use App\Services\Documents\DocumentIssuer;
use App\Services\Documents\SecondReview;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ConfirmDocument extends Component
{
    public int $caseId;

    public int $documentId;

    public string $reference = '';

    public bool $referenceDirty = false;

    public string $expectedStatus = '';

    public string $notice = '';

    public function mount(string $case, string $document): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('confirm', $record);
        $draft = $record->documents()->findOrFail($document);
        abort_unless($draft->document_type === DocumentType::DefenseLetter, 404);
        abort_unless($draft->is_locked || ($draft->status === DocumentStatus::ReadyForReview
            && ($draft->version > 1 || $record->status === CaseStatus::DefenseLetterReadyForReview)), 404);
        $this->caseId = $record->id;
        $this->documentId = $draft->id;
        $this->expectedStatus = $record->status->value;
        $this->reference = $draft->is_locked ? (string) $draft->reference_number : app(DocumentIssuer::class)->previewReference($draft);
    }

    public function updatedReference(): void
    {
        $this->referenceDirty = true;
    }

    public function markReviewed(SecondReview $reviews): void
    {
        $draft = $this->currentDocument();
        Gate::authorize('secondReview', $draft->disciplinaryCase);
        try {
            $reviews->mark($draft, auth()->user());
            $this->notice = 'سُجلت المراجعة الثانية.';
        } catch (IssuanceBlocked $error) {
            $this->notice = $error->getMessage();
        }
    }

    public function issue(DefenseLetterFlow $flow): void
    {
        $draft = $this->currentDocument(allowIssued: true);
        Gate::authorize('issue', $draft->disciplinaryCase);
        if ($draft->is_locked && filled($draft->reference_number)) {
            $this->finish($draft);

            return;
        }
        $this->validate(['reference' => ['nullable', 'string', 'max:100']]);
        try {
            $issued = $flow->confirmAndIssue($draft, auth()->user(), $this->referenceDirty ? $this->reference : null);
        } catch (IssuanceBlocked|TransitionBlocked $error) {
            $this->notice = $error->getMessage();

            return;
        }
        $this->finish($issued);
    }

    public function sendBack(DocumentDrafts $drafts): void
    {
        $draft = $this->currentDocument();
        $drafts->backToEdit($draft, auth()->user());
        $this->redirect('/cases/'.$this->caseId.'/documents/'.$this->documentId.'/edit', navigate: true);
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('confirm', $case);
        $document = $case->documents()->findOrFail($this->documentId);

        return view('livewire.cases.confirm-document', ['record' => $case, 'document' => $document])->layout('layouts.workspace');
    }

    private function currentDocument(bool $allowIssued = false): Document
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('confirm', $case);
        $document = $case->documents()->findOrFail($this->documentId);
        if ($allowIssued && $document->is_locked && filled($document->reference_number)) {
            return $document;
        }
        if ($case->status->value !== $this->expectedStatus) {
            throw ValidationException::withMessages(['notice' => 'تغيرت الحالة، أعد التحميل']);
        }

        return $document;
    }

    private function finish(Document $document): void
    {
        $message = 'صدر الكتاب بالعدد '.$document->reference_number;
        if (! $this->referenceDirty && $this->reference !== $document->reference_number) {
            $message .= ' — تغير الرقم المقترح أثناء العمل؛ الرقم المعتمد هو '.$document->reference_number;
        }
        session()->flash('case_success_'.$this->caseId, $message);
        $this->redirect('/cases/'.$this->caseId, navigate: true);
    }
}
