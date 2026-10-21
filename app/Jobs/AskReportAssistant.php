<?php

namespace App\Jobs;

use App\Ai\ClaudeClient;
use App\Ai\PayloadBuilder;
use App\Ai\PermanentAiFailure;
use App\Ai\ScanBlocks;
use App\Models\ReportAssistantSession;
use App\Services\Documents\DocumentText;
use App\Services\Documents\MsgReader;
use App\Services\Reports\ReportAssistant;
use App\Services\Reports\ReportFiles;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AskReportAssistant implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public array $backoff = [2, 5];

    public function __construct(public int $sessionId, public int $companyId, public string $token) {}

    public function handle(ClaudeClient $claude, ReportAssistant $assistant, ?ReportFiles $files = null, ?DocumentText $documents = null): void
    {
        $files ??= app(ReportFiles::class);
        $documents ??= new DocumentText(app(MsgReader::class), $claude, app(ScanBlocks::class));
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($claude, $assistant, $files, $documents): void {
            $session = ReportAssistantSession::query()->find($this->sessionId);
            if (! $session || $session->status !== 'pending' || $session->request_token !== $this->token) {
                return;
            }
            try {
                foreach ($files->active($session) as $file) {
                    if ($file->textReading !== null) {
                        continue;
                    }
                    try {
                        [$extracted, $logId] = $documents->read($file);
                        $status = filled($extracted) ? 'read' : 'no_text';
                    } catch (Throwable) {
                        $extracted = null;
                        $logId = null;
                        $status = 'unreadable';
                    }
                    $file->textReading()->firstOrCreate(['attachment_id' => $file->id], [
                        'company_id' => $file->company_id, 'extracted_text' => $extracted,
                        'reading_status' => $status, 'ai_generation_log_id' => $logId, 'created_at' => now(),
                    ]);
                }
                $builder = app(PayloadBuilder::class);
                $context = $assistant->context($session, $builder);
                $response = $claude->structured('report_assistant', $assistant->schema($context['locations']), $context['messages'],
                    ['company_id' => $session->company_id, 'actor_user_id' => $session->user_id],
                    (string) config('displanery.ai.report_assistant_model'), 'low',
                    ['system' => $assistant->systemPrompt(), 'max_tokens' => 4096]);
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
