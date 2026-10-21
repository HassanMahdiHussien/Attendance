<?php

namespace Tests\Fakes;

use App\Ai\ClaudeClient;
use App\Ai\SdkClaudeClient;
use App\LegalLibrary\BatchClient;
use App\Models\AiGenerationLog;
use Throwable;

class FakeClaudeClient implements BatchClient, ClaudeClient
{
    public array $requests = [];

    public array $responses = [];

    public array $batchRequests = [];

    public array $batchRows = [];

    public string $batchStatus = 'in_progress';

    public bool $batchCancelled = false;

    public function push(array|Throwable $response): void
    {
        $this->responses[] = $response;
    }

    public function structured(string $touchpoint, array $schema, array $messages, array $logContext, string $model, string $effort, array $options = []): array
    {
        $this->requests[] = compact('touchpoint', 'schema', 'messages', 'logContext', 'model', 'effort', 'options');
        $response = array_shift($this->responses);
        $log = AiGenerationLog::create([
            'company_id' => $logContext['company_id'] ?? null,
            'case_id' => $logContext['case_id'] ?? null,
            'document_id' => $logContext['document_id'] ?? null,
            'actor_user_id' => $logContext['actor_user_id'] ?? null,
            'touchpoint' => $touchpoint, 'model' => $model,
            'input' => json_encode((new SdkClaudeClient)->safeMessages(isset($options['system'])
                ? [['role' => 'system', 'content' => $options['system']], ...$messages] : $messages,
                $logContext['attachment_id'] ?? null), JSON_UNESCAPED_UNICODE),
            'output' => is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE) : null,
            'status' => $response instanceof Throwable ? 'failed' : 'succeeded',
            'error' => $response instanceof Throwable ? $response->getMessage() : null,
            'created_at' => now(),
        ]);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return [...($response ?? []), '_generation_log_id' => $log->id];
    }

    public function create(array $requests): string
    {
        $this->batchRequests = $requests;

        return 'msgbatch_fake';
    }

    public function status(string $batchId): string
    {
        return $this->batchStatus;
    }

    public function results(string $batchId): iterable
    {
        yield from $this->batchRows;
    }

    public function cancel(string $batchId): void
    {
        $this->batchCancelled = true;
    }
}
