<?php

namespace App\Livewire\Cases;

use App\Documents\CitationGate;
use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\PenaltyType;
use App\Enums\PoolRole;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Livewire\Cases\Concerns\ReviewsCitations;
use App\Models\Document;
use App\Services\Cases\CaseDocumentIssuance;
use App\Services\Cases\DefenseLetterFlow;
use App\Services\Cases\TerminationLeaveConfirmation;
use App\Services\Documents\DocumentDrafts;
use App\Services\Documents\DocumentIssuer;
use App\Services\Documents\SecondReview;
use App\Support\Access\CaseVisibility;
use App\Support\Format\Ui;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class ConfirmDocument extends Component
{
    use ReviewsCitations;

    public int $caseId;

    public int $documentId;

    public string $reference = '';

    public bool $referenceDirty = false;

    public string $expectedStatus = '';

    public string $notice = '';

    public bool $leaveChecked = false;

    public function mount(string $case, string $document): void
    {
        $record = CaseVisibility::for(auth()->user())->findOrFail($case);
        Gate::authorize('confirm', $record);
        $draft = $record->documents()->findOrFail($document);
        abort_unless($draft->is_locked || ($draft->status === DocumentStatus::ReadyForReview
            && ($draft->version > 1 || in_array($record->status, [CaseStatus::DefenseLetterReadyForReview,
                CaseStatus::RecommendationsAwaitingApprovals, CaseStatus::RecommendationsConfirmed,
                CaseStatus::AwaitingExternalLegalConsultation, CaseStatus::DecisionLetterReadyForReview], true))), 404);
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
            $this->notice = __('messages.document.second_review_recorded');
        } catch (IssuanceBlocked $error) {
            $this->notice = $error->getMessage();
        }
    }

    public function confirmLeave(TerminationLeaveConfirmation $confirmations): void
    {
        $draft = $this->currentDocument();
        if ($draft->document_type !== DocumentType::DecisionLetter || $draft->penalty_type !== PenaltyType::Termination) {
            abort(404);
        }
        $this->validate(['leaveChecked' => ['accepted']]);
        $confirmations->confirm($draft, auth()->user());
        $this->notice = __('messages.document.leave_clearance_recorded');
    }

    public function issue(DefenseLetterFlow $flow, CaseDocumentIssuance $issuance): void
    {
        $draft = $this->currentDocument(allowIssued: true);
        Gate::authorize('issue', $draft->disciplinaryCase);
        if ($draft->is_locked && filled($draft->reference_number)) {
            $this->finish($draft);

            return;
        }
        $this->validate(['reference' => ['nullable', 'string', 'max:100']]);
        try {
            $issued = $draft->document_type === DocumentType::DefenseLetter
                ? $flow->confirmAndIssue($draft, auth()->user(), $this->referenceDirty ? $this->reference : null)
                : $issuance->issue($draft, auth()->user(), $this->referenceDirty ? $this->reference : null);
        } catch (IssuanceBlocked|TransitionBlocked $error) {
            $this->notice = $error->getMessage();

            return;
        }
        $this->finish($issued);
    }

    public function sendBack(DocumentDrafts $drafts): void
    {
        $draft = $this->currentDocument();
        if ($draft->document_type === DocumentType::DefenseLetter) {
            $drafts->backToEdit($draft, auth()->user());
            $this->redirect('/cases/'.$this->caseId.'/documents/'.$this->documentId.'/edit', navigate: true);
        } elseif ($draft->document_type === DocumentType::Recommendations) {
            $this->redirect('/cases/'.$this->caseId.'/recommendations'.($draft->version > 1 ? '/'.$draft->id : ''), navigate: true);
        } else {
            $drafts->backToEditDecision($draft, auth()->user());
            $this->redirect('/cases/'.$this->caseId.'/decision', navigate: true);
        }
    }

    public function render(): View
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('confirm', $case);
        $document = $case->documents()->findOrFail($this->documentId);

        $blockers = [];
        if ($document->document_type === DocumentType::Recommendations) {
            foreach (PoolRole::cases() as $category) {
                if (! $document->approvals()->where('role_category', $category->value)->where('decision', 'approved')->whereNull('invalidated_at')->exists()) {
                    $blockers[] = __('messages.document.category_approval_required', ['category' => ['legal' => __('Legal'), 'hr' => __('HR'), 'labor_union' => __('Labor union')][$category->value]]);
                }
            }
        }
        if ($document->document_type === DocumentType::DecisionLetter) {
            if ($case->legal_review_requested_at && ! $case->legal_review_completed_at) {
                $blockers[] = __('messages.document.awaiting_legal_consultation');
            }
            if ($document->penalty_type === PenaltyType::Termination && ! $document->leave_confirmed_at) {
                $blockers[] = __('messages.document.leave_clearance_required');
            }
        }

        if (! $document->is_locked) {
            try {
                app(CitationGate::class)->assertIssuable($document);
            } catch (IssuanceBlocked $error) {
                $blockers[] = $error->getMessage();
            }
        }

        return view('livewire.cases.confirm-document', ['record' => $case, 'document' => $document, 'blockers' => $blockers])->layout('layouts.workspace');
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
            throw ValidationException::withMessages(['notice' => __('messages.case.changed_reload')]);
        }

        return $document;
    }

    private function finish(Document $document): void
    {
        $message = __('messages.document.issued_with_reference', ['reference' => Ui::number((string) $document->reference_number)]);
        if (! $this->referenceDirty && $this->reference !== $document->reference_number) {
            $message .= ' — '.__('messages.document.reference_changed', ['reference' => Ui::number((string) $document->reference_number)]);
        }
        session()->flash('case_success_'.$this->caseId, $message);
        $this->redirect('/cases/'.$this->caseId, navigate: true);
    }
}
