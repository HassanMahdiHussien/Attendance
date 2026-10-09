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
use App\Models\RegulationComparison;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\RecommendationsEditor;
use App\Services\Documents\DocumentDrafts;
use App\Services\Documents\RegulationExclusions;
use App\Support\Tenancy\TenantContext;
use App\Support\Text\ArabicText;
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
        public DocumentType $type, public string $token, public ?int $comparisonId = null) {}

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
            $comparison = $this->comparisonId ? RegulationComparison::find($this->comparisonId) : null;
            if ($this->comparisonId && (! $comparison || $comparison->case_id !== $case->id
                || ! in_array($comparison->status, ['confirmed', 'skipped'], true))) {
                return;
            }
            $supplement = $this->type === DocumentType::DecisionLetter
                ? $case->documents()->where('document_type', $this->type->value)->where('is_locked', false)->first()?->supplementaryComparison
                : null;
            if ($supplement && ! in_array($supplement->status, ['confirmed', 'skipped'], true)) {
                return;
            }
            $exclusions = app(RegulationExclusions::class);
            $excluded = $exclusions->forComparisons($case, $comparison, $supplement);
            $units = $candidates->for($case, $case->committee_session_notes ?? '', $excluded);
            $payload = $builder->build($case, $this->type, $units, $actor);
            $pairs = $comparison?->pairs()->get(['law_unit_id', 'regulation_unit_id', 'verdict'])
                ->map(fn ($pair) => ['law_unit_id' => $pair->law_unit_id,
                    'regulation_unit_id' => $pair->regulation_unit_id, 'verdict' => $pair->verdict])->all() ?? [];
            if ($supplement?->status === 'confirmed') {
                $pairs = array_merge($pairs, $supplement->pairs()->get(['law_unit_id', 'regulation_unit_id', 'verdict'])
                    ->map(fn ($pair) => ['law_unit_id' => $pair->law_unit_id,
                        'regulation_unit_id' => $pair->regulation_unit_id, 'verdict' => $pair->verdict])->all());
            }
            if ($comparison?->status === 'skipped' || $supplement?->status === 'skipped') {
                $payload['regulation_comparison'] = 'skipped_law_only';
            }
            $languages = app(DraftLanguages::class)->for($case->company, $this->type);
            try {
                $response = $claude->structured('draft_'.$this->type->value, $prompt->schema($languages, $this->type),
                    $prompt->messages($payload, $languages, $this->type, Cache::get('ai-draft-reasons:'.$this->token, []), $pairs),
                    ['company_id' => $case->company_id, 'case_id' => $case->id, 'actor_user_id' => $actor->id,
                        'document_id' => $case->documents()->where('document_type', $this->type->value)->where('is_locked', false)->value('id')],
                    (string) config('displanery.ai.drafting_model'), 'high');
            } catch (PermanentAiFailure $exception) {
                $this->failed($exception);
                $this->fail($exception);

                return;
            }
            foreach ($response['languages'] ?? [] as $language => $sections) {
                if (! is_array($sections)) {
                    continue;
                }
                foreach (['body', 'secondary_body'] as $section) {
                    if (isset($sections[$section]) && is_string($sections[$section])) {
                        $response['languages'][$language][$section] = ArabicText::westernDigits($sections[$section]);
                    }
                }
            }
            $errors = $validator->validate($response, $languages, $this->type, $units->pluck('id')->all(), $payload);
            if ($errors) {
                Cache::put('ai-draft-reasons:'.$this->token, $errors, now()->addMinutes(30));
                throw new InvalidAiOutput(implode('; ', $errors));
            }
            Cache::forget('ai-draft-reasons:'.$this->token);
            $flagged = $flag->flagged($response['languages'], $case->outcome?->value);
            $response = $builder->restore($response);
            DB::transaction(function () use ($response, $case, $actor, $workflow, $drafts, $recommendations, $audit, $events, $flagged, $units, $comparison): void {
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
                if ($existing && $comparison && $existing->regulation_comparison_id !== $comparison->id) {
                    $existing->forceFill(['regulation_comparison_id' => $comparison->id])->save();
                }
                $bodies = $response['languages'];
                if ($this->type === DocumentType::Recommendations) {
                    $document = $existing
                        ? $recommendations->update($existing, $bodies, $actor, DraftedBy::Ai)
                        : $recommendations->create($locked, $bodies, $actor, DraftedBy::Ai, $comparison?->id);
                } elseif ($this->type === DocumentType::DefenseLetter) {
                    $document = $drafts->createOrUpdate($locked, $this->type,
                        collect($bodies)->mapWithKeys(fn ($row, $language) => [$language => $row['body']])->all(),
                        $actor, $existing, DraftedBy::Ai, $comparison?->id);
                } else {
                    $document = $drafts->createOrUpdateDecision($locked,
                        collect($bodies)->mapWithKeys(fn ($row, $language) => [$language => $row['body']])->all(),
                        $actor, $existing, DraftedBy::Ai, $comparison?->id);
                }
                if ($comparison && $document->regulation_comparison_id !== $comparison->id) {
                    $document->forceFill(['regulation_comparison_id' => $comparison->id])->save();
                }
                $notes = array_slice($response['notes'], 0, 8);
                if ($units->isEmpty()) {
                    $notes[] = ['key' => 'messages.ai.no_verified_citations', 'params' => []];
                }
                if ($flagged) {
                    $notes[] = ['key' => 'messages.ai.review_outcome_wording', 'params' => []];
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
