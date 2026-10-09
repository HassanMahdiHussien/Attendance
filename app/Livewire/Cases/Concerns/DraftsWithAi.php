<?php

namespace App\Livewire\Cases\Concerns;

use App\Ai\AiDrafting;
use App\Ai\RegulationComparisons;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\Outcome;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\LegalUnit;
use App\Models\RegulationComparison;
use App\Services\Documents\RegulationExclusions;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

trait DraftsWithAi
{
    use ReviewsCitations;

    public bool $confirmAiReplacement = false;

    public bool $citationPickerOpen = false;

    public string $citationSearch = '';

    public array $comparisonVerdicts = [];

    public ?int $comparisonLawId = null;

    public ?int $comparisonRegulationId = null;

    public string $comparisonManualVerdict = 'consistent';

    public string $comparisonSkipReason = '';

    abstract protected function aiDocumentType(): DocumentType;

    abstract protected function loadAiBodies(Document $document): void;

    protected function aiCase(): DisciplinaryCase
    {
        $case = CaseVisibility::for(auth()->user())->findOrFail($this->caseId);
        Gate::authorize('manageDocuments', $case);

        return $case;
    }

    protected function aiStage(): CaseStatus
    {
        return match ($this->aiDocumentType()) {
            DocumentType::DefenseLetter => CaseStatus::Open,
            DocumentType::Recommendations => CaseStatus::CommitteeSessionHeld,
            DocumentType::DecisionLetter => CaseStatus::SignedRecommendationsUploaded,
        };
    }

    protected function aiBusyStage(): CaseStatus
    {
        return match ($this->aiDocumentType()) {
            DocumentType::DefenseLetter => CaseStatus::AiDraftingDefenseLetter,
            DocumentType::Recommendations => CaseStatus::AiDraftingRecommendations,
            DocumentType::DecisionLetter => CaseStatus::AiVerifyAndClose,
        };
    }

    protected function canRequestAi(DisciplinaryCase $case): bool
    {
        if ($case->status !== $this->aiStage() || $case->ai_request_id !== null || ! Gate::forUser(auth()->user())->allows('manageDocuments', $case)) {
            return false;
        }
        $draft = $case->documents()->where('document_type', $this->aiDocumentType()->value)->where('is_locked', false)->first();
        if ($draft && $draft->version !== 1) {
            return false;
        }
        $comparison = $this->activeComparison($case);
        if ($comparison && ! in_array($comparison->status, ['confirmed', 'skipped'], true)) {
            return false;
        }

        return match ($this->aiDocumentType()) {
            DocumentType::DefenseLetter => true,
            DocumentType::Recommendations => filled($case->committee_session_notes) && (bool) $case->session_majority_reached && $case->outcome !== null,
            DocumentType::DecisionLetter => in_array($case->outcome, [Outcome::Warning, Outcome::Termination], true)
                && $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('status', 'issued')->exists(),
        };
    }

    protected function aiFailed(DisciplinaryCase $case): bool
    {
        $requested = $case->events()->where('event_type', 'ai_draft_requested')->latest('id')->first();
        if ($case->ai_request_id !== null || data_get($requested?->payload, 'document_type') !== $this->aiDocumentType()->value) {
            return false;
        }

        return $case->events()->where('id', '>', $requested->id)
            ->whereIn('event_type', ['ai_draft_saved', 'ai_draft_failed'])
            ->latest('id')->value('event_type') === 'ai_draft_failed';
    }

    public function requestAiDraft(AiDrafting $drafting): void
    {
        $case = $this->aiCase();
        abort_unless($this->canRequestAi($case), 403);
        $hasDraft = $case->documents()->where('document_type', $this->aiDocumentType()->value)->where('is_locked', false)->exists();
        if ($hasDraft && ! $this->confirmAiReplacement) {
            $this->confirmAiReplacement = true;

            return;
        }
        try {
            $drafting->request($case, $this->aiDocumentType(), auth()->user(), replaceConfirmed: $hasDraft && $this->confirmAiReplacement);
        } catch (TransitionBlocked $error) {
            $this->notice = $error->getMessage();

            return;
        }
        $this->confirmAiReplacement = false;
        $this->expectedStatus = $case->fresh()->status->value;
        $this->notice = '';
    }

    protected function activeComparison(DisciplinaryCase $case): ?RegulationComparison
    {
        if ($this->documentId) {
            $document = $case->documents()->find($this->documentId);
            $supplement = $document?->supplementaryComparison;
            if ($supplement) {
                return $supplement;
            }
            if ($document?->regulationComparison) {
                return $document->regulationComparison;
            }
        }

        if ($this->aiDocumentType() === DocumentType::DecisionLetter) {
            $inherited = $case->documents()->where('document_type', DocumentType::Recommendations->value)
                ->where('status', 'issued')->latest('version')->first()?->regulationComparison;
            if ($inherited) {
                return $inherited;
            }
        }

        return app(RegulationComparisons::class)->pending($case, $this->aiDocumentType());
    }

    public function startSupplementaryComparison(RegulationComparisons $comparisons): void
    {
        $case = $this->aiCase();
        abort_unless($this->aiDocumentType() === DocumentType::DecisionLetter && $this->documentId, 403);
        $comparisons->startSupplementary($case->documents()->findOrFail($this->documentId), auth()->user());
    }

    public function startDocumentComparison(RegulationComparisons $comparisons): void
    {
        $case = $this->aiCase();
        abort_unless($this->documentId, 403);
        $comparisons->startForDocument($case->documents()->findOrFail($this->documentId), auth()->user());
    }

    public function startManualComparison(RegulationComparisons $comparisons): void
    {
        $case = $this->aiCase();
        abort_if($this->documentId, 403);
        $comparisons->startManual($case, $this->aiDocumentType(), auth()->user());
    }

    public function addComparisonPair(RegulationComparisons $comparisons): void
    {
        $case = $this->aiCase();
        $comparison = $this->activeComparison($case);
        abort_unless($comparison && $this->comparisonLawId && $this->comparisonRegulationId, 403);
        $comparisons->addPair($comparison, $this->comparisonLawId, $this->comparisonRegulationId,
            $this->comparisonManualVerdict, auth()->user());
        $this->comparisonLawId = $this->comparisonRegulationId = null;
    }

    public function confirmComparison(RegulationComparisons $comparisons): void
    {
        $comparison = $this->activeComparison($this->aiCase());
        abort_unless($comparison, 404);
        $comparisons->confirm($comparison, auth()->user(), $this->comparisonVerdicts);
        $this->notice = __('messages.regulation.confirmed');
    }

    public function skipComparison(RegulationComparisons $comparisons): void
    {
        $comparison = $this->activeComparison($this->aiCase());
        abort_unless($comparison, 404);
        $comparisons->skip($comparison, auth()->user(), $this->comparisonSkipReason);
        $this->notice = __('messages.regulation.skipped');
    }

    public function pollAiDraft(): void
    {
        $case = $this->aiCase();
        if ($case->ai_request_id !== null) {
            return;
        }
        $this->expectedStatus = $case->status->value;
        if ($this->aiFailed($case)) {
            $this->notice = '';

            return;
        }
        $document = $case->documents()->where('document_type', $this->aiDocumentType()->value)->where('is_locked', false)->latest('id')->first();
        if ($document) {
            $this->documentId = $document->id;
            $this->loadAiBodies($document);
            $this->notice = __('messages.document.ai_draft_saved_review');
        }
    }

    public function openCitationPicker(): void
    {
        $this->aiCase();
        $this->citationPickerOpen = true;
    }

    public function closeCitationPicker(): void
    {
        $this->aiCase();
        $this->citationPickerOpen = false;
    }

    protected function citableUnits(DisciplinaryCase $case): Collection
    {
        if (! $this->citationPickerOpen) {
            return collect();
        }
        $search = trim($this->citationSearch);

        $document = $this->documentId ? $case->documents()->find($this->documentId) : null;
        $excluded = $document ? app(RegulationExclusions::class)->for($document)
            : app(RegulationExclusions::class)->forPending($case, $this->aiDocumentType());

        return LegalUnit::query()->citableFor($case)->whereNotIn('legal_units.id', $excluded)->with('law')->where(function ($query) use ($search): void {
            if ($search !== '') {
                $query->where('article_number', 'like', '%'.$search.'%')
                    ->orWhere('clause_path', 'like', '%'.$search.'%')
                    ->orWhere('official_text', 'like', '%'.$search.'%')
                    ->orWhereHas('law', fn ($law) => $law->where('name', 'like', '%'.$search.'%'));
            }
        })->limit(30)->get();
    }

    public function selectCitation(int $unitId): void
    {
        $case = $this->aiCase();
        $document = $this->documentId ? $case->documents()->find($this->documentId) : null;
        $excluded = $document ? app(RegulationExclusions::class)->for($document)
            : app(RegulationExclusions::class)->forPending($case, $this->aiDocumentType());
        abort_unless($this->citationPickerOpen && LegalUnit::query()->citableFor($case)->whereKey($unitId)->exists(), 404);
        if (in_array($unitId, $excluded, true)) {
            $this->notice = __($document
                ? app(RegulationExclusions::class)->refusalKey($document, $unitId)
                : 'messages.regulation.compare_before_citing');

            return;
        }
        $this->dispatch('citation-selected', marker: '[[cite:'.$unitId.']]');
        $this->citationPickerOpen = false;
    }
}
