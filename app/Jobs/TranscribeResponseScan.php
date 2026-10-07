<?php

namespace App\Jobs;

use App\Ai\ClaudeClient;
use App\Ai\InvalidAiOutput;
use App\Ai\PermanentAiFailure;
use App\Ai\ScanBlocks;
use App\Enums\CaseStatus;
use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Models\ResponseTranscription;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Services\Cases\ResponseReading;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class TranscribeResponseScan implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 120];

    public function __construct(public int $caseId, public int $companyId, public int $actorId,
        public int $attachmentId, public string $token) {}

    public function handle(ClaudeClient $claude, ScanBlocks $blocks, ResponseReading $reading, CaseEventLogger $events): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function () use ($claude, $blocks, $reading, $events): void {
            $case = DisciplinaryCase::find($this->caseId);
            if (! $case || ! $this->matches($case, $reading)) {
                return;
            }
            $attachment = Attachment::findOrFail($this->attachmentId);
            try {
                $response = $claude->structured('transcribe_response', ['type' => 'object', 'properties' => [
                    'text' => ['type' => 'string'], 'illegible' => ['type' => 'boolean']],
                    'required' => ['text', 'illegible'], 'additionalProperties' => false],
                    [['role' => 'user', 'content' => [
                        $blocks->for($attachment),
                        ['type' => 'text', 'text' => 'Transcribe the handwritten employee response verbatim in its own language. Preserve line breaks. Mark unreadable words [؟]. Do not correct, summarize, translate, or infer. Set illegible true when substantial text cannot be read.'],
                    ]]], ['company_id' => $case->company_id, 'case_id' => $case->id,
                        'actor_user_id' => $this->actorId, 'attachment_id' => $attachment->id],
                    (string) config('displanery.ai.reading_model'), 'high');
            } catch (PermanentAiFailure $exception) {
                $this->failed($exception);
                $this->fail($exception);

                return;
            }
            if (! is_string($response['text'] ?? null) || ! is_bool($response['illegible'] ?? null)) {
                throw new InvalidAiOutput('Invalid transcription.');
            }
            DB::transaction(function () use ($response, $reading, $events): void {
                $locked = DisciplinaryCase::query()->lockForUpdate()->find($this->caseId);
                if (! $locked || ! $this->matches($locked, $reading)) {
                    return;
                }
                ResponseTranscription::create(['company_id' => $locked->company_id, 'case_id' => $locked->id,
                    'attachment_id' => $this->attachmentId, 'origin' => 'ai', 'text' => $response['text'],
                    'illegible' => $response['illegible'], 'ai_generation_log_id' => $response['_generation_log_id'] ?? null,
                    'created_at' => now()]);
                $this->clear($locked);
                $events->log($locked, 'response_reading_ready', ['attachment_id' => $this->attachmentId]);
            });
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->runForCompany($this->companyId, function (): void {
            DB::transaction(function (): void {
                $case = DisciplinaryCase::query()->lockForUpdate()->find($this->caseId);
                if (! $case || ! $this->matches($case, app(ResponseReading::class))) {
                    return;
                }
                $this->clear($case);
                app(CaseEventLogger::class)->log($case, 'response_reading_failed', ['attachment_id' => $this->attachmentId], actor: User::find($this->actorId));
            });
        });
    }

    private function matches(DisciplinaryCase $case, ResponseReading $reading): bool
    {
        return $case->ai_request_id === $this->token && $case->ai_request_document_type === 'response_transcription'
            && $case->status === CaseStatus::ResponseScannedUploaded
            && (int) $case->ai_request_attachment_id === $this->attachmentId
            && $reading->latestScan($case)?->id === $this->attachmentId;
    }

    private function clear(DisciplinaryCase $case): void
    {
        $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
            'ai_request_from_status' => null, 'ai_request_attachment_id' => null, 'ai_request_started_at' => null])->save();
    }
}
