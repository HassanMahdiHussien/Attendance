<?php

namespace App\LegalLibrary;

use Anthropic\Client;
use Anthropic\Messages\Batches\MessageBatchSucceededResult;
use Anthropic\Messages\TextBlock;

class SdkBatchClient implements BatchClient
{
    public function create(array $requests): string
    {
        return $this->client()->messages->batches->create(requests: $requests)->id;
    }

    public function status(string $batchId): string
    {
        return $this->client()->messages->batches->retrieve($batchId)->processingStatus;
    }

    public function results(string $batchId): iterable
    {
        foreach ($this->client()->messages->batches->resultsStream($batchId) as $row) {
            $result = $row->result;
            if ($result instanceof MessageBatchSucceededResult) {
                $message = $result->message;
                yield ['custom_id' => $row->customID, 'type' => $result->type,
                    'output' => collect($message->content)->first(fn ($block) => $block instanceof TextBlock)?->text,
                    'stop_reason' => $message->stopReason,
                    'input_tokens' => $message->usage->inputTokens,
                    'output_tokens' => $message->usage->outputTokens];
            } else {
                yield ['custom_id' => $row->customID, 'type' => $result->type];
            }
        }
    }

    public function cancel(string $batchId): void
    {
        $this->client()->messages->batches->cancel($batchId);
    }

    private function client(): Client
    {
        return new Client(apiKey: (string) config('services.anthropic.key'));
    }
}
