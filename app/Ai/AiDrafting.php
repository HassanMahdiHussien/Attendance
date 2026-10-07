<?php

namespace App\Ai;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\Outcome;
use App\Exceptions\TransitionBlocked;
use App\Jobs\DraftDocumentWithAi;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AiDrafting
{
    public function __construct(private CaseWorkflow $workflow, private AuditLogger $audit, private CaseEventLogger $events) {}

    public function request(DisciplinaryCase $case, DocumentType $type, User $actor, bool $replaceConfirmed = false): void
    {
        DB::transaction(function () use ($case, $type, $actor, $replaceConfirmed): void {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            Gate::forUser($actor)->authorize('manageDocuments', $case);
            $from = match ($type) {
                DocumentType::DefenseLetter => CaseStatus::Open,
                DocumentType::Recommendations => CaseStatus::CommitteeSessionHeld,
                DocumentType::DecisionLetter => CaseStatus::SignedRecommendationsUploaded,
            };
            $to = match ($type) {
                DocumentType::DefenseLetter => CaseStatus::AiDraftingDefenseLetter,
                DocumentType::Recommendations => CaseStatus::AiDraftingRecommendations,
                DocumentType::DecisionLetter => CaseStatus::AiVerifyAndClose,
            };
            if ($case->status !== $from || $case->ai_request_id !== null) {
                throw new TransitionBlocked('AI drafting is unavailable at this stage.');
            }
            if ($type === DocumentType::Recommendations && (blank($case->committee_session_notes)
                || ! $case->session_majority_reached || $case->outcome === null)) {
                throw new TransitionBlocked('Committee minutes, majority and outcome are required.');
            }
            if ($type === DocumentType::DecisionLetter && (! in_array($case->outcome, [Outcome::Warning, Outcome::Termination], true)
                || ! $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('status', 'issued')->exists())) {
                throw new TransitionBlocked('Issued recommendations with a penalty are required.');
            }
            $draft = $case->documents()->where('document_type', $type->value)->where('is_locked', false)->first();
            if ($draft && ($draft->version !== 1 || ! $replaceConfirmed)) {
                throw new TransitionBlocked('Replacing the current draft requires confirmation.');
            }
            $token = (string) Str::uuid();
            $this->workflow->transition($case, $to, $actor, 'AI draft requested');
            $case->forceFill(['ai_request_id' => $token, 'ai_request_document_type' => $type->value,
                'ai_request_from_status' => $from->value, 'ai_request_started_at' => now()])->save();
            $this->audit->log('ai.draft_requested', $case, ['document_type' => $type->value], $actor);
            $this->events->log($case, 'ai_draft_requested', ['document_type' => $type->value], actor: $actor);
            DB::afterCommit(fn () => DraftDocumentWithAi::dispatch($case->id, $case->company_id, $actor->id, $type, $token));
        });
    }
}
