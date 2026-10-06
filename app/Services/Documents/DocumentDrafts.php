<?php

namespace App\Services\Documents;

use App\Documents\CitationGate;
use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DraftedBy;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DocumentDrafts
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function createOrUpdate(DisciplinaryCase $case, DocumentType $type, array $bodies, User $actor, ?Document $draft = null): Document
    {
        return DB::transaction(function () use ($case, $type, $bodies, $actor, $draft): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            Gate::forUser($actor)->authorize('manageDocuments', $case);
            if ($type !== DocumentType::DefenseLetter || ($draft?->version === 1
                && ! in_array($case->status, [CaseStatus::Open, CaseStatus::DefenseLetterCitationCheck, CaseStatus::DefenseLetterReadyForReview], true))) {
                throw new TransitionBlocked('The document cannot be edited at this stage.');
            }
            if ($draft !== null) {
                $draft = Document::query()->lockForUpdate()->where('case_id', $case->id)->findOrFail($draft->id);
            } else {
                $draft = $case->documents()->where('document_type', $type->value)->whereIn('status', ['draft', 'ready_for_review'])->orderByDesc('version')->lockForUpdate()->first();
            }
            if ($draft?->is_locked || ($draft !== null && $draft->document_type !== $type)) {
                throw new TransitionBlocked('Issued documents cannot be edited.');
            }
            $languages = array_values(array_unique(array_merge(['ar', 'en'], $case->company->enabled_languages)));
            if (array_diff($languages, array_keys($bodies)) || array_diff(array_keys($bodies), $languages)) {
                throw ValidationException::withMessages(['bodies' => 'يجب كتابة النص بكل اللغات المفعلة.']);
            }
            foreach ($bodies as $language => $body) {
                if (! is_string($body) || blank($body)) {
                    throw ValidationException::withMessages(["bodies.{$language}" => 'النص مطلوب.']);
                }
            }
            if ($draft === null) {
                if ($case->status !== CaseStatus::Open) {
                    throw new TransitionBlocked('The case changed, reload.');
                }
                $draft = Document::create([
                    'company_id' => $case->company_id, 'case_id' => $case->id, 'document_type' => $type,
                    'drafted_by' => DraftedBy::Human, 'status' => DocumentStatus::Draft,
                    'version' => 1, 'created_by' => $actor->id,
                ]);
            }
            $changed = false;
            foreach ($bodies as $language => $body) {
                $content = $draft->contents()->firstOrNew(['language_code' => $language]);
                if (! $content->exists || $content->body !== $body) {
                    $content->fill(['company_id' => $case->company_id, 'body' => $body, 'is_authoritative' => $language === 'ar']);
                    $content->save();
                    $changed = true;
                }
            }
            if ($changed) {
                $draft->forceFill(['last_edited_by' => $actor->id, 'second_reviewed_by' => null,
                    'second_reviewed_at' => null, 'status' => DocumentStatus::Draft])->save();
                if ($draft->version === 1 && in_array($case->status, [CaseStatus::DefenseLetterCitationCheck, CaseStatus::DefenseLetterReadyForReview], true)) {
                    $this->workflow->transition($case, CaseStatus::Open, $actor, 'Defense letter text changed');
                }
            }
            $this->events->log($case, 'document_draft_saved', ['document_id' => $draft->id, 'content_changed' => $changed], actor: $actor);

            return $draft->refresh();
        });
    }

    public function submit(Document $document, User $actor): void
    {
        $case = DisciplinaryCase::query()->findOrFail($document->case_id);
        Gate::forUser($actor)->authorize('manageDocuments', $case);
        $document = $case->documents()->findOrFail($document->id);
        if ($document->is_locked || $document->document_type !== DocumentType::DefenseLetter) {
            throw new TransitionBlocked('The document cannot be submitted.');
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
            throw new TransitionBlocked('The case changed, reload.');
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
                throw new TransitionBlocked('The case changed, reload.');
            }
            $this->workflow->transition($case, CaseStatus::Open, $actor, 'Document sent back to edit');
            $document->status = DocumentStatus::Draft;
            $document->save();
        });
    }
}
