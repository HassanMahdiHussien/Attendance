<?php

namespace App\Services\Documents;

use App\Documents\CitationGate;
use App\Documents\DraftLanguages;
use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DraftedBy;
use App\Enums\Outcome;
use App\Enums\PenaltyType;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\RegulationComparison;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseWorkflow;
use App\Support\Access\CaseVisibility;
use App\Support\Text\ArabicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentDrafts
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function createOrUpdate(DisciplinaryCase $case, DocumentType $type, array $bodies, User $actor, ?Document $draft = null, DraftedBy $origin = DraftedBy::Human, ?int $comparisonId = null): Document
    {
        return DB::transaction(function () use ($case, $type, $bodies, $actor, $draft, $origin, $comparisonId): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            Gate::forUser($actor)->authorize('manageDocuments', $case);
            if ($type !== DocumentType::DefenseLetter || ($draft?->version === 1
                && ! in_array($case->status, [CaseStatus::Open, CaseStatus::DefenseLetterCitationCheck, CaseStatus::DefenseLetterReadyForReview], true))) {
                throw new TransitionBlocked(__('messages.document.edit_wrong_stage'));
            }
            if ($draft !== null) {
                $draft = Document::query()->lockForUpdate()->where('case_id', $case->id)->findOrFail($draft->id);
            } else {
                $draft = $case->documents()->where('document_type', $type->value)->whereIn('status', ['draft', 'ready_for_review'])->orderByDesc('version')->lockForUpdate()->first();
            }
            if ($draft?->is_locked || ($draft !== null && $draft->document_type !== $type)) {
                throw new TransitionBlocked(__('messages.document.issued_immutable'));
            }
            $languages = app(DraftLanguages::class)->for($case->company, $type);
            if (array_diff($languages, array_keys($bodies)) || array_diff(array_keys($bodies), $languages)) {
                throw ValidationException::withMessages(['bodies' => __('messages.document.body_all_languages_required')]);
            }
            foreach ($bodies as $language => $body) {
                if (! is_string($body) || blank($body)) {
                    throw ValidationException::withMessages(["bodies.{$language}" => __('messages.document.body_required')]);
                }
            }
            if ($draft === null) {
                if ($case->status !== CaseStatus::Open) {
                    throw new TransitionBlocked(__('messages.case.changed_reload_period'));
                }
                $comparisonId ??= RegulationComparison::query()->where('case_id', $case->id)
                    ->where('document_type', $type->value)->whereIn('status', ['confirmed', 'skipped'])
                    ->latest('id')->value('id');
                $draft = Document::create([
                    'company_id' => $case->company_id, 'case_id' => $case->id, 'document_type' => $type,
                    'drafted_by' => $origin, 'status' => DocumentStatus::Draft,
                    'version' => 1, 'created_by' => $actor->id, 'regulation_comparison_id' => $comparisonId,
                ]);
                if ($comparisonId) {
                    $comparison = RegulationComparison::findOrFail($comparisonId);
                    if ($comparison->consumed_at === null) {
                        $comparison->forceFill(['consumed_at' => now()])->save();
                    }
                }
            }
            if ($comparisonId && $draft->regulation_comparison_id !== $comparisonId) {
                $draft->forceFill(['regulation_comparison_id' => $comparisonId])->save();
            }
            $changed = false;
            foreach ($bodies as $language => $body) {
                $body = ArabicText::westernDigits($body);
                $content = $draft->contents()->firstOrNew(['language_code' => $language]);
                if (! $content->exists || $content->body !== $body) {
                    $content->fill(['company_id' => $case->company_id, 'body' => $body, 'is_authoritative' => $language === 'ar']);
                    $content->save();
                    $changed = true;
                }
            }
            if ($changed) {
                $draft->drafted_by = $origin;
                $draft->forceFill(['last_edited_by' => $actor->id, 'second_reviewed_by' => null,
                    'second_reviewed_at' => null, 'status' => DocumentStatus::Draft])->save();
                if ($draft->version === 1 && in_array($case->status, [CaseStatus::DefenseLetterCitationCheck, CaseStatus::DefenseLetterReadyForReview], true)) {
                    $this->workflow->transition($case, CaseStatus::Open, $actor, 'Defense letter text changed');
                }
            }
            app(CitationSync::class)->sync($draft);
            $this->events->log($case, 'document_draft_saved', ['document_id' => $draft->id, 'content_changed' => $changed], actor: $actor);

            return $draft->refresh();
        });
    }

    public function createOrUpdateDecision(DisciplinaryCase $case, array $bodies, User $actor, ?Document $draft = null, DraftedBy $origin = DraftedBy::Human, ?int $comparisonId = null): Document
    {
        return DB::transaction(function () use ($case, $bodies, $actor, $draft, $origin, $comparisonId): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            $comparisonId ??= $draft?->regulation_comparison_id;
            $comparisonId ??= $case->documents()->where('document_type', DocumentType::DecisionLetter->value)
                ->where('is_locked', false)->value('regulation_comparison_id');
            $comparisonId ??= $case->documents()->where('document_type', DocumentType::Recommendations->value)
                ->where('status', DocumentStatus::Issued->value)->orderByDesc('version')->first()?->regulation_comparison_id;
            $comparisonId ??= RegulationComparison::query()->where('case_id', $case->id)
                ->where('document_type', DocumentType::DecisionLetter->value)
                ->whereIn('status', ['confirmed', 'skipped'])->latest('id')->value('id');
            Gate::forUser($actor)->authorize('manageDocuments', $case);
            if (($draft === null || $draft->version === 1)
                && ! in_array($case->status, [CaseStatus::SignedRecommendationsUploaded, CaseStatus::DecisionLetterCitationCheck], true)
                || ! in_array($case->outcome, [Outcome::Warning, Outcome::Termination], true)
                || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked(__('messages.document.signed_penalty_recommendation_required'));
            }
            $languages = app(DraftLanguages::class)->for($case->company, DocumentType::DecisionLetter);
            if (array_diff($languages, array_keys($bodies)) || array_diff(array_keys($bodies), $languages)) {
                throw ValidationException::withMessages(['bodies' => __('messages.document.decision_reasons_all_languages_required')]);
            }
            foreach ($bodies as $language => $body) {
                if (! is_string($body) || blank($body)) {
                    throw ValidationException::withMessages(["bodies.{$language}" => __('messages.document.decision_reasons_required')]);
                }
            }
            $document = $draft ? $case->documents()->lockForUpdate()->findOrFail($draft->id)
                : $case->documents()->where('document_type', DocumentType::DecisionLetter->value)->where('is_locked', false)->lockForUpdate()->first();
            if ($document && ($document->is_locked || $document->document_type !== DocumentType::DecisionLetter)) {
                throw new TransitionBlocked(__('messages.document.issued_immutable'));
            }
            if (! $document) {
                $document = Document::create(['company_id' => $case->company_id, 'case_id' => $case->id,
                    'document_type' => DocumentType::DecisionLetter, 'penalty_type' => PenaltyType::from($case->outcome->value),
                    'drafted_by' => $origin, 'status' => DocumentStatus::Draft,
                    'version' => 1, 'created_by' => $actor->id, 'regulation_comparison_id' => $comparisonId]);
                if ($comparisonId) {
                    $comparison = RegulationComparison::findOrFail($comparisonId);
                    if ($comparison->consumed_at === null) {
                        $comparison->forceFill(['consumed_at' => now()])->save();
                    }
                }
            }
            if ($comparisonId && $document->regulation_comparison_id !== $comparisonId) {
                $document->forceFill(['regulation_comparison_id' => $comparisonId])->save();
            }
            foreach ($bodies as $language => $body) {
                $body = ArabicText::westernDigits($body);
                $document->contents()->updateOrCreate(['language_code' => $language],
                    ['company_id' => $case->company_id, 'body' => $body, 'is_authoritative' => $language === 'ar']);
            }
            $document->forceFill(['drafted_by' => $origin, 'last_edited_by' => $actor->id, 'second_reviewed_by' => null,
                'second_reviewed_at' => null, 'status' => DocumentStatus::Draft])->save();
            app(CitationSync::class)->sync($document);
            $this->events->log($case, 'decision_draft_saved', ['document_id' => $document->id], actor: $actor);

            return $document->refresh();
        });
    }

    public function submitDecision(Document $draft, User $actor): Document
    {
        return DB::transaction(function () use ($draft, $actor): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($document->case_id);
            Gate::forUser($actor)->authorize('manageDocuments', $case);
            if ($document->document_type !== DocumentType::DecisionLetter || $document->is_locked
                || $document->version !== 1 || ! in_array($case->status, [CaseStatus::SignedRecommendationsUploaded, CaseStatus::DecisionLetterCitationCheck], true)
                || $case->outcome?->value !== $document->penalty_type?->value) {
                throw new TransitionBlocked(__('messages.document.decision_submit_wrong_stage'));
            }
            if ($case->status === CaseStatus::SignedRecommendationsUploaded) {
                $case = $this->workflow->transition($case, CaseStatus::DecisionLetterCitationCheck, $actor, 'Decision draft submitted');
            }
            app(CitationGate::class)->assertIssuable($document);
            $document->forceFill(['status' => DocumentStatus::ReadyForReview])->save();
            $target = $case->legal_review_requested_at && ! $case->legal_review_completed_at
                ? CaseStatus::AwaitingExternalLegalConsultation : CaseStatus::DecisionLetterReadyForReview;
            $this->workflow->transition($case, $target, $actor, 'Decision citation check passed');
            $this->events->log($case, 'decision_submitted_for_review', ['document_id' => $document->id], actor: $actor);

            return $document->refresh();
        });
    }

    public function backToEditDecision(Document $draft, User $actor): void
    {
        DB::transaction(function () use ($draft, $actor): void {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($document->case_id);
            Gate::forUser($actor)->authorize('manageDocuments', $case);
            if ($document->document_type !== DocumentType::DecisionLetter || $document->is_locked
                || $case->status !== CaseStatus::DecisionLetterReadyForReview) {
                throw new TransitionBlocked(__('messages.document.decision_cannot_return_to_editing'));
            }
            $this->workflow->transition($case, CaseStatus::DecisionLetterCitationCheck, $actor, 'Decision returned to edit');
            $document->forceFill(['status' => DocumentStatus::Draft, 'second_reviewed_by' => null,
                'second_reviewed_at' => null])->save();
            $this->events->log($case, 'document_sent_back', ['document_id' => $document->id], actor: $actor);
        });
    }

    public function submit(Document $document, User $actor): void
    {
        $case = DisciplinaryCase::query()->findOrFail($document->case_id);
        Gate::forUser($actor)->authorize('manageDocuments', $case);
        $document = $case->documents()->findOrFail($document->id);
        if ($document->is_locked || $document->document_type !== DocumentType::DefenseLetter) {
            throw new TransitionBlocked(__('messages.document.cannot_submit'));
        }
        if ($document->version > 1) {
            app(CitationGate::class)->assertIssuable($document);
            DB::transaction(function () use ($document, $case, $actor): void {
                $document->status = DocumentStatus::ReadyForReview;
                $document->save();
                $this->events->log($case, 'document_submitted_for_review', ['document_id' => $document->id], actor: $actor);
            });

            return;
        }
        if ($case->status === CaseStatus::Open) {
            $case = $this->workflow->transition($case, CaseStatus::DefenseLetterCitationCheck, $actor, 'Defense draft submitted');
        }
        if ($case->status !== CaseStatus::DefenseLetterCitationCheck) {
            throw new TransitionBlocked(__('messages.case.changed_reload_period'));
        }
        DB::transaction(function () use ($case, $document, $actor): void {
            $this->workflow->transition($case, CaseStatus::DefenseLetterReadyForReview, $actor, 'Citation check passed');
            $document->status = DocumentStatus::ReadyForReview;
            $document->save();
        });
    }

    public function backToEdit(Document $document, User $actor): void
    {
        DB::transaction(function () use ($document, $actor): void {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($document->case_id);
            Gate::forUser($actor)->authorize('manageDocuments', $case);
            abort_unless($document->case_id === $case->id && ! $document->is_locked, 404);
            if ($document->version > 1) {
                $document->status = DocumentStatus::Draft;
                $document->save();
                $this->events->log($case, 'document_sent_back', ['document_id' => $document->id], actor: $actor);

                return;
            }
            if (! in_array($case->status, [CaseStatus::DefenseLetterCitationCheck, CaseStatus::DefenseLetterReadyForReview], true)) {
                throw new TransitionBlocked(__('messages.case.changed_reload_period'));
            }
            $this->workflow->transition($case, CaseStatus::Open, $actor, 'Document sent back to edit');
            $document->status = DocumentStatus::Draft;
            $document->save();
        });
    }
}
