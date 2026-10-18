<?php

namespace App\Jobs;

use App\Ai\ClaudeClient;
use App\Ai\PayloadBuilder;
use App\Ai\PermanentAiFailure;
use App\Models\ReportAssistantSession;
use App\Services\Reports\ReportAssistant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AskReportAssistant implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [2, 5];

    public function __construct(public int $sessionId, public int $companyId, public string $token) {}

    public function handle(ClaudeClient $claude, ReportAssistant $assistant): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($claude, $assistant): void {
            $session = ReportAssistantSession::query()->find($this->sessionId);
            if (! $session || $session->status !== 'pending' || $session->request_token !== $this->token) {
                return;
            }
            try {
                $builder = app(PayloadBuilder::class);
                $context = $assistant->context($session, $builder);
                $response = $claude->structured('report_assistant', $assistant->schema($context['locations']), $context['messages'],
                    ['company_id' => $session->company_id, 'actor_user_id' => $session->user_id],
                    (string) config('displanery.ai.report_assistant_model'), 'low',
                    ['system' => $assistant->systemPrompt(), 'max_tokens' => 1024]);
                $session->refresh();
                if ($session->status === 'pending' && $session->request_token === $this->token) {
                    $assistant->acceptTurn($session, $response, $context, $builder);
                }
            } catch (PermanentAiFailure $exception) {
                $this->failed($exception);
                $this->fail($exception);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function (): void {
            $session = ReportAssistantSession::query()->find($this->sessionId);
            if ($session && $session->status === 'pending' && $session->request_token === $this->token) {
                $session->update(['status' => 'fallback']);
            }
        });
    }
}
