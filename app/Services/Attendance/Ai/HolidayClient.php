<?php

namespace App\Services\Attendance\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\RequestOptions;
use LogicException;
use Psr\Http\Client\ClientInterface;
use Throwable;

class HolidayClient
{
    public const MODEL = 'claude-opus-5-5';

    public function __construct(private ?ClientInterface $transport = null) {}

    public function send(array $blocks, array $known, array $schema, string $system, ?callable $onAttempt = null, int $maxAttempts = 3): array
    {
        $client = new Client(apiKey: (string) config('services.anthropic.key'), requestOptions: $this->transport
            ? RequestOptions::with(transporter: $this->transport) : null);
        $messages = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => json_encode(['known_company_values' => $known], JSON_THROW_ON_ERROR)],
            ...$blocks,
        ]]];

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            try {
                $response = $client->beta->messages->create(
                    maxTokens: 16000,
                    messages: $messages,
                    model: self::MODEL,
                    fallbacks: 'default',
                    outputConfig: ['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
                    system: $system,
                    betas: ['server-side-fallback-2026-07-01'],
                    requestOptions: RequestOptions::with(timeout: 120, maxRetries: 0),
                );
            } catch (APIStatusException|APIConnectionException $exception) {
                if ($onAttempt !== null) {
                    $onAttempt('failed', $exception instanceof APIStatusException ? 'http_'.$exception->status : 'connection', null);
                }
                $retryable = $exception instanceof APIConnectionException
                    || ($exception->status === 429 || $exception->status >= 500);
                if (! $retryable || $attempt === $maxAttempts - 1) {
                    throw $exception;
                }
                usleep(250000 * (2 ** $attempt));

                continue;
            } catch (Throwable $exception) {
                if ($onAttempt !== null) {
                    $onAttempt('failed', 'sdk_error', null);
                }

                throw $exception;
            }

            $result = [
                'model' => $response->model,
                'stop_reason' => $response->stopReason,
                'text' => implode('', array_map(fn ($block): string => $block->text ?? '', $response->content)),
                'input_tokens' => $response->usage->inputTokens,
                'output_tokens' => $response->usage->outputTokens,
            ];
            if ($onAttempt !== null) {
                $onAttempt($response->stopReason === 'end_turn' ? 'succeeded' : 'failed',
                    match ($response->stopReason) {
                        'end_turn' => null, 'refusal' => 'refused', 'max_tokens' => 'truncated',
                        default => 'unexpected_stop',
                    }, $result);
            }

            return $result;
        }

        throw new LogicException('Unreachable AI retry state.');
    }
}
