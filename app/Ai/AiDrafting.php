<?php

namespace App\Ai;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\Outcome;
use App\Exceptions\TransitionBlocked;
use App\Jobs\DraftDocumentWithAi;
use App\Jobs\SuggestRegulationComparison;
use App\Models\DisciplinaryCase;
use App\Models\RegulationComparison;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\IncidentPrivacy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AiDrafting
{
    public function __construct(private CaseWorkflow $workflow, private AuditLogger $audit,
        private CaseEventLogger $events, private RegulationComparisons $comparisons) {}

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
                throw new TransitionBlocked(__('messages.ai.drafting_unavailable'));
            }
            if ($case->incident_id !== null && $type !== DocumentType::DecisionLetter
                && filled($case->case_specific_description)
                && app(IncidentPrivacy::class)->containsOtherMemberName($case, $case->case_specific_description)) {
                throw new TransitionBlocked(__('messages.incident.other_member_in_text'));
            }
            if ($type === DocumentType::Recommendations && (blank($case->committee_session_notes)
                || ! $case->session_majority_reached || $case->outcome === null)) {
                throw new TransitionBlocked(__('messages.ai.session_outcome_required'));
            }
            if ($type === DocumentType::DecisionLetter && (! in_array($case->outcome, [Outcome::Warning, Outcome::Termination], true)
                || ! $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('status', 'issued')->exists())) {
                throw new TransitionBlocked(__('messages.ai.penalty_recommendations_required'));
            }
            $draft = $case->documents()->where('document_type', $type->value)->where('is_locked', false)->first();
            if ($draft && ($draft->version !== 1 || ! $replaceConfirmed)) {
                throw new TransitionBlocked(__('messages.ai.replace_draft_confirmation_required'));
            }
            if ($type === DocumentType::DecisionLetter && $draft?->supplementaryComparison
                && ! in_array($draft->supplementaryComparison->status, ['confirmed', 'skipped'], true)) {
                throw new TransitionBlocked(__('messages.regulation.confirm_first'));
            }
            $comparison = null;
            if ($type === DocumentType::DecisionLetter) {
                $comparison = $case->documents()->where('document_type', DocumentType::Recommendations->value)
                    ->where('status', 'issued')->orderByDesc('version')->first()?->regulationComparison;
            }
            if (! $comparison && $this->comparisons->required($case)) {
                $comparison = $this->comparisons->pending($case, $type);
                if (! $comparison || ! in_array($comparison->status, ['confirmed', 'skipped'], true)) {
                    if ($comparison && in_array($comparison->status, ['suggesting', 'ready', 'failed'], true)) {
                        throw new TransitionBlocked(__('messages.regulation.confirm_first'));
                    }
                    $token = (string) Str::uuid();
                    $comparison = RegulationComparison::create(['company_id' => $case->company_id,
                        'case_id' => $case->id, 'document_type' => $type->value, 'status' => 'suggesting',
                        'origin' => 'ai', 'request_token' => $token]);
                    $case->forceFill(['ai_request_id' => $token, 'ai_request_document_type' => 'regulation_comparison',
                        'ai_request_from_status' => $from->value, 'ai_request_started_at' => now()])->save();
                    $this->audit->log('regulation_comparison.requested', $comparison, ['document_type' => $type->value], $actor);
                    DB::afterCommit(fn () => SuggestRegulationComparison::dispatch($comparison->id, $case->id,
                        $case->company_id, $actor->id, $token));

                    return;
                }
            }
            $token = (string) Str::uuid();
            if ($comparison && $comparison->consumed_at === null) {
                $comparison->forceFill(['consumed_at' => now()])->save();
            }
            $this->workflow->transition($case, $to, $actor, 'AI draft requested');
            $case->forceFill(['ai_request_id' => $token, 'ai_request_document_type' => $type->value,
                'ai_request_from_status' => $from->value, 'ai_request_started_at' => now()])->save();
            $this->audit->log('ai.draft_requested', $case, ['document_type' => $type->value], $actor);
            $this->events->log($case, 'ai_draft_requested', ['document_type' => $type->value], actor: $actor);
            DB::afterCommit(fn () => DraftDocumentWithAi::dispatch($case->id, $case->company_id, $actor->id, $type, $token,
                $comparison?->id));
        });
    }
}
