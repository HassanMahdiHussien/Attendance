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
use Anthropic\Messages\TextBlock;
use App\Models\AiGenerationLog;
use Throwable;

class SdkClaudeClient implements ClaudeClient
{
    public function structured(string $touchpoint, array $schema, array $messages, array $logContext, string $model, string $effort): array
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
            $response = (new Client(apiKey: $key))->messages->create(
                model: $model, maxTokens: 16000, messages: $messages,
                outputConfig: ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
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
                'input' => json_encode($messages, JSON_UNESCAPED_UNICODE), 'output' => $output,
                'status' => $status, 'error' => $error, 'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens, 'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'created_at' => now(),
            ]);
        }

        $data['_generation_log_id'] = $log->id;

        return $data;
    }
}
