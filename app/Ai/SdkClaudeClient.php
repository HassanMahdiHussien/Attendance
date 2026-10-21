<?php

namespace App\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\ConflictException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\UnprocessableEntityException;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\Messages\TextBlock;
use App\Models\AiGenerationLog;
use Throwable;

class SdkClaudeClient implements ClaudeClient
{
    /**
     * Structured output rejects size keywords ("For 'array' type, property 'maxItems' is not supported"),
     * which made every intake-question call fail with HTTP 400. Callers keep them as documentation and
     * enforce the limits when validating the answer; only the copy sent to the API is stripped.
     */
    public static function apiSchema(array $schema): array
    {
        foreach (['maxItems', 'minItems', 'maxLength', 'minLength'] as $keyword) {
            unset($schema[$keyword]);
        }
        foreach ($schema as $key => $value) {
            if (is_array($value)) {
                $schema[$key] = self::apiSchema($value);
            }
        }

        return $schema;
    }

    public function structured(string $touchpoint, array $schema, array $messages, array $logContext, string $model, string $effort, array $options = []): array
    {
        $key = (string) config('services.anthropic.key');
        $started = microtime(true);
        $status = 'failed';
        $error = null;
        $output = null;
        $inputTokens = null;
        $outputTokens = null;
        try {
            if ($key === '') {
                // No key cannot be fixed by retrying: fail at once so the user sees the manual path immediately.
                throw new PermanentAiFailure('AI unavailable — write it manually or try again.');
            }
            // Streamed: a bilingual legal draft at high effort can run for minutes, longer than a
            // single blocking request should be held open.
            $stream = (new Client(apiKey: $key))->messages->createStream(
                model: $model, maxTokens: $options['max_tokens'] ?? 16000, messages: $messages,
                system: isset($options['system']) ? [['type' => 'text', 'text' => $options['system'],
                    'cacheControl' => ['type' => 'ephemeral']]] : null,
                outputConfig: ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => self::apiSchema($schema)]],
            );
            $accumulator = MessageAccumulator::forMessages();
            foreach ($stream as $event) {
                $accumulator->accumulate($event);
            }
            $response = $accumulator->message();
            $inputTokens = $response->usage->inputTokens;
            $outputTokens = $response->usage->outputTokens;
            // The model always thinks, so the first content block may be a thinking block; read the text block.
            $output = collect($response->content)->first(fn ($item) => $item instanceof TextBlock)?->text;
            if (in_array($response->stopReason, ['refusal', 'max_tokens'], true)) {
                throw new InvalidAiOutput('AI response was refused or truncated.');
            }
            $data = json_decode($output ?? '', true);
            if (! is_array($data)) {
                throw new InvalidAiOutput('AI response was not structured JSON.');
            }
            $status = 'succeeded';

        } catch (BadRequestException|AuthenticationException|PermissionDeniedException|NotFoundException|UnprocessableEntityException $exception) {
            $error = $exception->getMessage();
            throw new PermanentAiFailure('AI unavailable — write it manually or try again.', previous: $exception);
        } catch (ConflictException|RateLimitException|InternalServerException|APIConnectionException $exception) {
            $error = $exception->getMessage();
            throw new AiUnavailable('AI temporarily unavailable.', previous: $exception);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            throw $exception;
        } finally {
            $log = AiGenerationLog::create([
                'company_id' => $logContext['company_id'] ?? null, 'case_id' => $logContext['case_id'] ?? null,
                'document_id' => $logContext['document_id'] ?? null, 'actor_user_id' => $logContext['actor_user_id'] ?? null,
                'touchpoint' => $touchpoint, 'model' => $model,
                'input' => json_encode($this->safeMessages(isset($options['system'])
                    ? [['role' => 'system', 'content' => $options['system']], ...$messages] : $messages,
                    $logContext['attachment_id'] ?? null), JSON_UNESCAPED_UNICODE), 'output' => $output,
                'status' => $status, 'error' => $error, 'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens, 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'created_at' => now(),
            ]);
        }

        $data['_generation_log_id'] = $log->id;

        return $data;
    }

    public function safeMessages(array $messages, ?int $attachmentId = null): array
    {
        return array_map(function (array $message) use ($attachmentId): array {
            if (! is_array($message['content'] ?? null)) {
                return $message;
            }
            $message['content'] = array_map(function (array $block) use ($attachmentId): array {
                if (! in_array($block['type'] ?? null, ['image', 'document'], true)) {
                    return $block;
                }
                $source = $block['source'] ?? [];
                $bytes = base64_decode($source['data'] ?? '', true) ?: '';

                return ['type' => $block['type'], 'media_type' => $source['mediaType'] ?? null,
                    'attachment_id' => $attachmentId,
                    'sha256' => hash('sha256', $bytes), 'bytes' => strlen($bytes)];
            }, $message['content']);

            return $message;
        }, $messages);
    }
}
