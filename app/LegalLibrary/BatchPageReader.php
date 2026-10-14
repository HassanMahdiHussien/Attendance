<?php

namespace App\LegalLibrary;

use App\Models\AiGenerationLog;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BatchPageReader
{
    public function __construct(private BatchClient $client, private ClaudePageReader $reader,
        private ImportProgress $progress) {}

    public function submit(LegalImportRun $run): void
    {
        $run = LegalImportRun::query()->findOrFail($run->id);
        if ($run->state !== 'reading' || $run->reading_mode !== 'batch' || $run->batch_id !== null
            || $run->secondary_source_path !== null || $run->batch_state !== 'submitting') {
            return;
        }
        $pages = $run->pages()->where('ai_status', 'pending')->orderBy('page_number')->get();
        if ($pages->isEmpty()) {
            $this->progress->evaluate($run);

            return;
        }
        if ((string) config('services.anthropic.key') === '') {
            $this->failSubmission($run, 'AI reading unavailable — retry later.');

            return;
        }
        $requests = [];
        foreach ($pages as $page) {
            $requests[] = ['customID' => 'page-'.$page->id,
                'params' => $this->reader->requestParams(Storage::disk('local')->path($page->image_path),
                    $run->reading_model, $run->reading_effort)];
            if (strlen(json_encode(end($requests))) > 32 * 1024 * 1024) {
                $this->failSubmission($run, 'A page reading exceeds the API request size limit.');

                return;
            }
        }
        if (count($requests) > 100000 || strlen(json_encode($requests)) > 256 * 1024 * 1024) {
            $this->failSubmission($run, 'Reading batch exceeds the configured size limit.');

            return;
        }
        $batchId = $this->client->create($requests);
        $run->update(['batch_id' => $batchId, 'batch_state' => 'in_progress']);
        foreach ($pages as $page) {
            $this->log($run, $page, $batchId, 'submitted');
        }
    }

    public function poll(LegalImportRun $run): void
    {
        $run = LegalImportRun::query()->findOrFail($run->id);
        if ($run->batch_id === null || $run->state === 'cancelled') {
            return;
        }
        $state = $this->client->status($run->batch_id);
        $run->update(['batch_state' => $state]);
        if ($state !== 'ended') {
            return;
        }
        foreach ($this->client->results($run->batch_id) as $result) {
            if (! preg_match('/^page-([1-9][0-9]*)$/', $result['custom_id'] ?? '', $matches)) {
                continue;
            }
            $this->applyResult($run, (int) $matches[1], $result);
        }
        foreach ($run->pages()->where('ai_status', 'pending')->get() as $missing) {
            $this->applyResult($run, $missing->id, ['type' => 'missing']);
        }
        $this->progress->evaluate($run);
    }

    public function failSubmission(LegalImportRun $run, string $reason): void
    {
        $run->update(['batch_state' => 'failed']);
        foreach ($run->pages()->where('ai_status', 'pending')->get() as $page) {
            $page->update(['ai_status' => 'unavailable', 'ai_source' => 'api_batch',
                'attempts_ai' => $page->attempts_ai + 1, 'last_error' => $reason]);
            $this->log($run, $page, null, 'failed', null, $reason);
        }
        $this->progress->evaluate($run);
    }

    public function cancel(LegalImportRun $run): void
    {
        if ($run->batch_id === null) {
            return;
        }
        $this->client->cancel($run->batch_id);
        $run->update(['batch_state' => 'canceling']);
    }

    private function applyResult(LegalImportRun $run, int $pageId, array $result): void
    {
        DB::transaction(function () use ($run, $pageId, $result): void {
            $locked = LegalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->state !== 'reading' || $locked->batch_id !== $run->batch_id) {
                return;
            }
            $page = $locked->pages()->whereKey($pageId)->lockForUpdate()->first();
            if ($page === null || $page->ai_status !== 'pending') {
                return;
            }
            $data = ($result['type'] ?? null) === 'succeeded'
                && ! in_array($result['stop_reason'] ?? null, ['refusal', 'max_tokens'], true)
                ? json_decode($result['output'] ?? '', true) : null;
            if (is_array($data) && is_string($data['text'] ?? null) && is_bool($data['illegible'] ?? null)) {
                $page->update(['ai_text' => $data['text'], 'ai_illegible' => $data['illegible'],
                    'ai_status' => 'done', 'ai_source' => 'api_batch', 'attempts_ai' => $page->attempts_ai + 1]);
                $this->log($locked, $page, $locked->batch_id, 'succeeded', $data['text'], null,
                    $result['input_tokens'] ?? null, $result['output_tokens'] ?? null);
            } else {
                $error = 'Batch page reading '.($result['type'] ?? 'invalid').' — retry with AI.';
                $page->update(['ai_status' => ($result['type'] ?? null) === 'expired' ? 'unavailable' : 'failed',
                    'ai_source' => 'api_batch', 'attempts_ai' => $page->attempts_ai + 1, 'last_error' => $error]);
                $this->log($locked, $page, $locked->batch_id, 'failed', null, $error);
            }
        });
    }

    private function log(LegalImportRun $run, LegalImportPage $page, ?string $batchId, string $status,
        ?string $output = null, ?string $error = null, ?int $inputTokens = null, ?int $outputTokens = null): void
    {
        AiGenerationLog::create(['company_id' => $run->company_id, 'actor_user_id' => $run->started_by,
            'touchpoint' => 'legal_library_reading',
            'model' => $run->reading_model ?? (string) config('displanery.ai.legal_reading_model', 'claude-sonnet-5-5'),
            'input' => json_encode(['law_id' => $run->law_id, 'run_id' => $run->id,
                'page' => $page->page_number, 'image_sha256' => $page->image_sha256,
                'effort' => $run->reading_effort ?? config('displanery.ai.legal_reading_effort', 'low'),
                'batch_id' => $batchId]),
            'output' => $output, 'status' => $status, 'error' => $error,
            'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens, 'created_at' => now()]);
    }
}
