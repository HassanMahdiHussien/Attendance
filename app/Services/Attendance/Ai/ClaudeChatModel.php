<?php

namespace App\Services\Attendance\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\RequestOptions;
use Psr\Http\Client\ClientInterface;

class ClaudeChatModel implements ChatModel
{
    public const MODEL = 'claude-opus-5-5';

    public function __construct(private ?ClientInterface $transport = null) {}

    public function complete(array $messages, array $tools, string $system): array
    {
        $key = (string) config('services.anthropic.key');
        if ($key === '') {
            throw new ChatUnavailable('no_key');
        }
        $client = new Client(apiKey: $key, requestOptions: $this->transport
            ? RequestOptions::with(transporter: $this->transport) : null);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $response = $client->beta->messages->create(
                    maxTokens: 4096,
                    messages: $messages,
                    model: self::MODEL,
                    tools: $tools,
                    thinking: ['type' => 'adaptive'],
                    system: $system,
                    fallbacks: 'default',
                    betas: ['server-side-fallback-2026-07-01'],
                    requestOptions: RequestOptions::with(timeout: 120, maxRetries: 0),
                );

                return [
                    'model' => $response->model,
                    'stop_reason' => $response->stopReason,
                    'content' => array_map(fn ($block): array => json_decode(json_encode($block, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR), $response->content),
                    'input_tokens' => $response->usage->inputTokens,
                    'output_tokens' => $response->usage->outputTokens,
                ];
            } catch (APIConnectionException|APIStatusException $exception) {
                if ($attempt === 2 || ($exception instanceof APIStatusException
                    && $exception->status !== 429 && $exception->status < 500)) {
                    throw $exception;
                }
                usleep(250000 * (2 ** $attempt));
            }
        }

        throw new ChatUnavailable('retry_exhausted');
    }
}
