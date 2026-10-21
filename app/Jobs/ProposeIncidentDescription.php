<?php

namespace App\Jobs;

use App\Ai\ClaudeClient;
use App\Ai\PayloadBuilder;
use App\Enums\CaseStatus;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\Cases\IncidentPrivacy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ProposeIncidentDescription implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public array $backoff = [30, 120];

    public function __construct(public int $caseId, public int $companyId, public int $actorId) {}

    public function handle(ClaudeClient $claude, PayloadBuilder $builder, IncidentPrivacy $privacy): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($claude, $builder, $privacy): void {
            $case = DisciplinaryCase::find($this->caseId);
            if (! $case || $case->status !== CaseStatus::Open || $case->incident_id === null
                || filled($case->case_specific_description) || $case->documents()->exists()) {
                return;
            }
            $actor = User::findOrFail($this->actorId);
            $facts = $builder->incidentDescriptionFacts($case);
            $response = $claude->structured('incident_case_description', [
                'type' => 'object', 'properties' => ['description' => ['type' => 'string']],
                'required' => ['description'], 'additionalProperties' => false,
            ], [['role' => 'user', 'content' => 'Write one concise proposed description for this employee from only these shared incident facts. '
                .'Do not infer any employee action, identity, cause, or decision that the facts do not state. '
                .'When the facts do not state this employee’s action, describe only the known incident, place and time. '
                .'Do not name or refer to another incident member. Return the description for human review. Facts: '
                .json_encode($facts, JSON_UNESCAPED_UNICODE)]],
                ['company_id' => $case->company_id, 'case_id' => $case->id, 'actor_user_id' => $actor->id],
                (string) config('displanery.ai.drafting_model'), 'high');
            $description = trim((string) ($response['description'] ?? ''));
            if ($description === '' || mb_strlen($description) > 50000
                || $privacy->containsOtherMemberName($case, $description)
                || str_contains($description, '⟦')) {
                return;
            }
            $current = $case->fresh();
            if (! $current || $current->status !== CaseStatus::Open || filled($current->case_specific_description)
                || ! $current->updated_at->equalTo($case->updated_at)) {
                return;
            }
            Cache::put('incident-description-proposal:'.$case->id, $description, now()->addDays(7));
        });
    }

    public function failed(?Throwable $exception): void
    {
        Cache::forget('incident-description-proposal:'.$this->caseId);
    }
}
