<?php

namespace App\Jobs;

use App\Ai\CandidateUnits;
use App\Ai\ClaudeClient;
use App\Ai\InvalidAiOutput;
use App\Ai\PayloadBuilder;
use App\Ai\RegulationComparisons;
use App\Models\DisciplinaryCase;
use App\Models\RegulationComparison;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SuggestRegulationComparison implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public function __construct(public int $comparisonId, public int $caseId, public int $companyId,
        public int $actorId, public string $token) {}

    public function handle(CandidateUnits $candidates, PayloadBuilder $builder, ClaudeClient $claude,
        RegulationComparisons $comparisons): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($candidates, $builder, $claude, $comparisons): void {
            $case = DisciplinaryCase::find($this->caseId);
            $comparison = RegulationComparison::find($this->comparisonId);
            if (! $case || ! $comparison || $case->ai_request_id !== $this->token
                || $case->ai_request_document_type !== 'regulation_comparison'
                || $comparison->request_token !== $this->token || $comparison->status !== 'suggesting') {
                return;
            }
            $units = $candidates->for($case, '');
            $payload = $builder->comparison($case, $units);
            $response = $claude->structured('regulation_comparison', [
                'type' => 'object', 'properties' => ['pairs' => ['type' => 'array', 'items' => [
                    'type' => 'object', 'properties' => [
                        'law_unit_id' => ['type' => 'integer'], 'regulation_unit_id' => ['type' => 'integer'],
                        'verdict' => ['type' => 'string', 'enum' => ['consistent', 'conflict', 'more_favourable']],
                        'reason' => ['type' => 'string'],
                    ], 'required' => ['law_unit_id', 'regulation_unit_id', 'verdict', 'reason'], 'additionalProperties' => false,
                ]]], 'required' => ['pairs'], 'additionalProperties' => false,
            ], [['role' => 'user', 'content' => 'Suggest matching statute and internal regulation pairs from these candidate IDs only. A regulation giving a worker more than the legal minimum is more_favourable, never conflict. This is a nonbinding review aid; HR decides. Return concise reasons. '.json_encode($payload, JSON_UNESCAPED_UNICODE)]],
                ['company_id' => $case->company_id, 'case_id' => $case->id, 'actor_user_id' => $this->actorId],
                (string) config('displanery.ai.drafting_model'), 'high');
            if (! is_array($response['pairs'] ?? null)) {
                throw new InvalidAiOutput('Invalid comparison pairs');
            }
            $allowed = $units->pluck('id')->all();
            foreach ($response['pairs'] as $pair) {
                if (! is_array($pair) || ! is_int($pair['law_unit_id'] ?? null)
                    || ! is_int($pair['regulation_unit_id'] ?? null)
                    || ! in_array($pair['law_unit_id'], $allowed, true)
                    || ! in_array($pair['regulation_unit_id'], $allowed, true)
                    || ! in_array($pair['verdict'] ?? null, ['consistent', 'conflict', 'more_favourable'], true)
                    || ! is_string($pair['reason'] ?? null) || blank($pair['reason'])
                    || mb_strlen($pair['reason']) > 500 || str_contains($pair['reason'], "\n")) {
                    throw new InvalidAiOutput('Invalid comparison pair');
                }
                $comparisons->validatePair($case, $pair['law_unit_id'], $pair['regulation_unit_id']);
            }
            DB::transaction(function () use ($response): void {
                $case = DisciplinaryCase::query()->lockForUpdate()->find($this->caseId);
                $comparison = RegulationComparison::query()->lockForUpdate()->find($this->comparisonId);
                if (! $case || ! $comparison || $case->ai_request_id !== $this->token
                    || $case->ai_request_document_type !== 'regulation_comparison'
                    || $comparison->request_token !== $this->token || $comparison->status !== 'suggesting') {
                    return;
                }
                foreach ($response['pairs'] as $pair) {
                    $comparison->pairs()->firstOrCreate(['law_unit_id' => $pair['law_unit_id'], 'regulation_unit_id' => $pair['regulation_unit_id']],
                        ['company_id' => $this->companyId, 'ai_verdict' => $pair['verdict'], 'ai_reason' => $pair['reason'],
                            'verdict' => $pair['verdict'], 'changed_by_hr' => false]);
                }
                $comparison->forceFill(['status' => 'ready', 'ai_generation_log_id' => $response['_generation_log_id'] ?? null])->save();
                $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
                    'ai_request_from_status' => null, 'ai_request_started_at' => null])->save();
                app(AuditLogger::class)->log('regulation_comparison.suggested', $comparison,
                    ['pair_count' => count($response['pairs'])], null);
            });
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function (): void {
            DB::transaction(function (): void {
                $case = DisciplinaryCase::query()->lockForUpdate()->find($this->caseId);
                $comparison = RegulationComparison::query()->lockForUpdate()->find($this->comparisonId);
                if (! $case || ! $comparison || $case->ai_request_id !== $this->token
                    || $case->ai_request_document_type !== 'regulation_comparison'
                    || $comparison->request_token !== $this->token || $comparison->status !== 'suggesting') {
                    return;
                }
                $comparison->forceFill(['status' => 'failed'])->save();
                app(AuditLogger::class)->log('regulation_comparison.failed', $comparison, [], null);
                $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
                    'ai_request_from_status' => null, 'ai_request_started_at' => null])->save();
            });
        });
    }
}
