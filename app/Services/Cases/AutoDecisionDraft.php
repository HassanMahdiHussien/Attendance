<?php

namespace App\Services\Cases;

use App\Ai\AiDrafting;
use App\Ai\AiUnavailable;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\Outcome;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\CaseEventLogger;
use Illuminate\Support\Facades\Log;
use Throwable;

class AutoDecisionDraft
{
    public function __construct(private AiDrafting $drafting, private CaseEventLogger $events) {}

    public function afterSignedRecommendations(DisciplinaryCase $case, User $actor): void
    {
        try {
            if ($case->status !== CaseStatus::SignedRecommendationsUploaded
                || ! in_array($case->outcome, [Outcome::Warning, Outcome::Termination], true)
                || $case->ai_request_id !== null
                || ! $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('status', 'issued')->exists()
                || $case->documents()->where('document_type', DocumentType::DecisionLetter->value)->exists()) {
                return;
            }
            $this->drafting->request($case, DocumentType::DecisionLetter, $actor);
            $case->refresh();
            if ($case->status === CaseStatus::AiVerifyAndClose
                && $case->ai_request_document_type === DocumentType::DecisionLetter->value) {
                $this->events->log($case, 'ai_draft_auto_requested', ['document_type' => DocumentType::DecisionLetter->value], actor: $actor);
            } else {
                $this->events->log($case, 'ai_draft_auto_skipped', ['reason' => 'comparison_required'], actor: $actor);
            }
        } catch (Throwable $exception) {
            Log::warning('Automatic decision draft request skipped', ['case_id' => $case->id, 'exception' => $exception::class]);
            try {
                $reason = match (true) {
                    $exception instanceof TransitionBlocked => 'request_blocked',
                    $exception instanceof AiUnavailable => 'ai_unavailable',
                    default => 'request_failed',
                };
                $this->events->log($case, 'ai_draft_auto_skipped', ['reason' => $reason], actor: $actor);
            } catch (Throwable $loggingException) {
                Log::warning('Automatic decision draft skip could not be recorded', ['case_id' => $case->id, 'exception' => $loggingException::class]);
            }
        }
    }
}
