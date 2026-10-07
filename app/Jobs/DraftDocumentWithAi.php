<?php

namespace App\Jobs;

use App\Ai\CandidateUnits;
use App\Ai\ClaudeClient;
use App\Ai\DraftPrompt;
use App\Ai\DraftValidator;
use App\Ai\InvalidAiOutput;
use App\Ai\OutcomeKeywordFlag;
use App\Ai\PayloadBuilder;
use App\Ai\PermanentAiFailure;
use App\Documents\DraftLanguages;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\DraftedBy;
use App\Models\AiDraftNote;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\RecommendationsEditor;
use App\Services\Documents\DocumentDrafts;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class DraftDocumentWithAi implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public function __construct(public int $caseId, public int $companyId, public int $actorId,
        public DocumentType $type, public string $token) {}

    public function handle(CandidateUnits $candidates, PayloadBuilder $builder, DraftPrompt $prompt,
        DraftValidator $validator, OutcomeKeywordFlag $flag, ClaudeClient $claude,
        CaseWorkflow $workflow, DocumentDrafts $drafts, RecommendationsEditor $recommendations,
        AuditLogger $audit, CaseEventLogger $events): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($candidates, $builder, $prompt,
            $validator, $flag, $claude, $workflow, $drafts, $recommendations, $audit, $events): void {
            $case = DisciplinaryCase::find($this->caseId);
            if (! $case || $case->ai_request_id !== $this->token) {
                return;
            }
            $actor = User::findOrFail($this->actorId);
            $units = $candidates->for($case, $case->committee_session_notes ?? '');
            $payload = $builder->build($case, $this->type, $units);
            $languages = app(DraftLanguages::class)->for($case->company, $this->type);
            try {
                $response = $claude->structured('draft_'.$this->type->value, $prompt->schema($languages, $this->type),
                    $prompt->messages($payload, $languages, $this->type, Cache::get('ai-draft-reasons:'.$this->token, [])),
                    ['company_id' => $case->company_id, 'case_id' => $case->id, 'actor_user_id' => $actor->id,
                        'document_id' => $case->documents()->where('document_type', $this->type->value)->where('is_locked', false)->value('id')],
                    (string) config('displanery.ai.drafting_model'), 'high');
            } catch (PermanentAiFailure $exception) {
                $this->failed($exception);
                $this->fail($exception);

                return;
            }
            $errors = $validator->validate($response, $languages, $this->type, $units->pluck('id')->all(), $payload);
            if ($errors) {
                Cache::put('ai-draft-reasons:'.$this->token, $errors, now()->addMinutes(30));
                throw new InvalidAiOutput(implode('; ', $errors));
            }
            Cache::forget('ai-draft-reasons:'.$this->token);
            $flagged = $flag->flagged($response['languages'], $case->outcome?->value);
            $response = $builder->restore($response);
            DB::transaction(function () use ($response, $case, $actor, $workflow, $drafts, $recommendations, $audit, $events, $flagged, $units): void {
                $locked = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
                $expected = match ($this->type) {
                    DocumentType::DefenseLetter => CaseStatus::AiDraftingDefenseLetter,
                    DocumentType::Recommendations => CaseStatus::AiDraftingRecommendations,
                    DocumentType::DecisionLetter => CaseStatus::AiVerifyAndClose,
                };
                if ($locked->ai_request_id !== $this->token || $locked->status !== $expected
                    || $locked->ai_request_document_type !== $this->type->value) {
                    return;
                }
                $workflow->transition($locked, CaseStatus::from($locked->ai_request_from_status), null, 'AI draft prepared');
                $existing = $locked->documents()->where('document_type', $this->type->value)->where('is_locked', false)->first();
                $bodies = $response['languages'];
                if ($this->type === DocumentType::Recommendations) {
                    $document = $existing
                        ? $recommendations->update($existing, $bodies, $actor, DraftedBy::Ai)
                        : $recommendations->create($locked, $bodies, $actor, DraftedBy::Ai);
                } elseif ($this->type === DocumentType::DefenseLetter) {
                    $document = $drafts->createOrUpdate($locked, $this->type,
                        collect($bodies)->mapWithKeys(fn ($row, $language) => [$language => $row['body']])->all(),
                        $actor, $existing, DraftedBy::Ai);
                } else {
                    $document = $drafts->createOrUpdateDecision($locked,
                        collect($bodies)->mapWithKeys(fn ($row, $language) => [$language => $row['body']])->all(),
                        $actor, $existing, DraftedBy::Ai);
                }
                $notes = array_slice($response['notes'], 0, 8);
                if ($units->isEmpty()) {
                    $notes[] = 'لا توجد مواد موثقة قابلة للاستشهاد';
                }
                if ($flagged) {
                    $notes[] = 'راجع ذكر العقوبة أو النتيجة في النص المصاغ';
                }
                AiDraftNote::create(['company_id' => $locked->company_id, 'case_id' => $locked->id,
                    'document_id' => $document->id, 'ai_generation_log_id' => $response['_generation_log_id'],
                    'notes' => $notes, 'created_at' => now()]);
                $locked->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
                    'ai_request_from_status' => null, 'ai_request_attachment_id' => null, 'ai_request_started_at' => null])->save();
                $audit->log('ai.draft_saved', $document, ['document_type' => $this->type->value], $actor);
                $events->log($locked, 'ai_draft_saved', ['document_id' => $document->id], actor: $actor);
            });
        });
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget('ai-draft-reasons:'.$this->token);
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($exception): void {
            DB::transaction(function () use ($exception): void {
                $case = DisciplinaryCase::query()->lockForUpdate()->find($this->caseId);
                if (! $case || $case->ai_request_id !== $this->token) {
                    return;
                }
                $actor = User::find($this->actorId);
                app(CaseWorkflow::class)->transition($case, CaseStatus::from($case->ai_request_from_status), null, 'AI draft failed');
                $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
                    'ai_request_from_status' => null, 'ai_request_attachment_id' => null, 'ai_request_started_at' => null])->save();
                app(AuditLogger::class)->log('ai.draft_failed', $case, ['error' => $exception?->getMessage()], $actor);
                app(CaseEventLogger::class)->log($case, 'ai_draft_failed', ['reason' => 'AI unavailable — write it manually or try again'], actor: $actor);
            });
        });
    }
}
