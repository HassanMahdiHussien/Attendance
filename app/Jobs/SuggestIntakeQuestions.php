<?php

namespace App\Jobs;

use App\Ai\ClaudeClient;
use App\Ai\IntakeQuestions;
use App\Ai\PayloadBuilder;
use App\Ai\PermanentAiFailure;
use App\Models\IntakeCheck;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SuggestIntakeQuestions implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public function __construct(public int $checkId, public int $companyId, public string $token) {}

    public function handle(ClaudeClient $claude, IntakeQuestions $questions, PayloadBuilder $builder): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($claude, $questions, $builder): void {
            $check = IntakeCheck::query()->find($this->checkId);
            if (! $check || $check->status !== 'pending' || $check->request_token !== $this->token) {
                return;
            }
            try {
                $response = $claude->structured('intake_questions', $questions->schema(),
                    $questions->messages($builder->minimizeIntake($check->input), $check->language),
                    ['company_id' => $check->company_id, 'actor_user_id' => $check->user_id],
                    (string) config('displanery.ai.reading_model'), 'low');
                $result = $questions->validate($response);
            } catch (PermanentAiFailure $exception) {
                $this->failed($exception);
                $this->fail($exception);

                return;
            }
            DB::transaction(function () use ($result, $response): void {
                $check = IntakeCheck::query()->lockForUpdate()->find($this->checkId);
                if ($check && $check->status === 'pending' && $check->request_token === $this->token) {
                    $check->update(['status' => 'ready', 'result' => $result, 'input' => [],
                        'ai_generation_log_id' => $response['_generation_log_id'] ?? null]);
                }
            });
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function (): void {
            DB::transaction(function (): void {
                $check = IntakeCheck::query()->lockForUpdate()->find($this->checkId);
                if ($check && $check->status === 'pending' && $check->request_token === $this->token) {
                    $check->update(['status' => 'checklist', 'input' => [],
                        'result' => app(IntakeQuestions::class)->checklist($check->language)]);
                }
            });
        });
    }
}
