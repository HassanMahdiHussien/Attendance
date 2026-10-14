<?php

namespace App\LegalLibrary;

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

class ClaudePageReader implements PageReader
{
    public function read(string $pngPath, array $context): PageReading
    {
        $key = (string) config('services.anthropic.key');
        $model = (string) config('displanery.ai.legal_reading_model', 'claude-sonnet-5-5');
        $effort = (string) config('displanery.ai.legal_reading_effort', 'low');
        if ($key === '') {
            AiGenerationLog::create(['company_id' => $context['company_id'] ?? null,
                'actor_user_id' => $context['actor_user_id'] ?? null,
                'touchpoint' => 'legal_library_reading',
                'model' => $model,
                'input' => json_encode(['law_id' => $context['law_id'] ?? null, 'run_id' => $context['run_id'] ?? null,
                    'page' => $context['page'] ?? null, 'effort' => $effort,
                    'image_sha256' => hash_file('sha256', $pngPath)]),
                'status' => 'failed', 'error' => 'AI reading unavailable — retry later.', 'created_at' => now()]);
            throw new AiUnavailable('AI reading unavailable — retry later.');
        }
        $started = microtime(true);
        $status = 'failed';
        $error = null;
        $output = null;
        $inputTokens = null;
        $outputTokens = null;
        try {
            $response = (new Client(apiKey: $key))->messages->create(...$this->requestParams($pngPath));
            $inputTokens = $response->usage->inputTokens;
            $outputTokens = $response->usage->outputTokens;
            // The model always thinks, so the first content block may be a thinking block; read the text block.
            $block = collect($response->content)->first(fn ($item) => $item instanceof TextBlock);
            $data = json_decode($block?->text ?? '', true);
            if (! is_array($data) || ! is_string($data['text'] ?? null) || ! is_bool($data['illegible'] ?? null)) {
                throw new PermanentPageFailure('AI reading format is invalid.');
            }
            $output = $data['text'];
            $status = 'succeeded';

            return new PageReading($data['text'], $data['illegible']);
        } catch (BadRequestException|AuthenticationException|PermissionDeniedException|NotFoundException|ConflictException|UnprocessableEntityException $exception) {
            $error = $exception->getMessage();
            throw new PermanentPageFailure($error, previous: $exception);
        } catch (RateLimitException|InternalServerException|APIConnectionException $exception) {
            $error = $exception->getMessage();
            throw new RetryablePageFailure($error, previous: $exception);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
            throw $exception;
        } finally {
            AiGenerationLog::create([
                'company_id' => $context['company_id'] ?? null,
                'actor_user_id' => $context['actor_user_id'] ?? null,
                'touchpoint' => 'legal_library_reading', 'model' => $model,
                'input' => json_encode(['law_id' => $context['law_id'] ?? null, 'run_id' => $context['run_id'] ?? null,
                    'page' => $context['page'] ?? null, 'effort' => $effort,
                    'image_sha256' => hash_file('sha256', $pngPath)], JSON_UNESCAPED_UNICODE),
                'output' => $output, 'status' => $status, 'error' => $error,
                'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'created_at' => now(),
            ]);
        }
    }

    public function requestParams(string $pngPath, ?string $model = null, ?string $effort = null): array
    {
        $image = imagecreatefrompng($pngPath);
        if ($image === false) {
            throw new PermanentPageFailure('Page image is invalid.');
        }
        $width = imagesx($image);
        $height = imagesy($image);
        $edge = (int) config('displanery.legal_library.ai_long_edge', 1568);
        if (max($width, $height) > $edge) {
            $scale = $edge / max($width, $height);
            $small = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            imagedestroy($image);
            $image = $small;
        }
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);
        $instruction = 'انسخ النص العربي كما هو حرفياً وبترتيب الأسطر. لا تصحح الإملاء ولا تضف ترقيم المواد. ضع [؟] مكان كل كلمة غير مقروءة، واجعل illegible=true إذا كان جزء كبير غير مقروء. Transcribe verbatim in line order. Do not correct spelling or add numbering. Mark unreadable words [؟]; set illegible=true if a substantial part is unreadable.';

        return [
            'model' => $model ?? (string) config('displanery.ai.legal_reading_model', 'claude-sonnet-5-5'),
            'maxTokens' => 16000,
            'messages' => [['role' => 'user', 'content' => [
                ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => 'image/png', 'data' => base64_encode($bytes)]],
                ['type' => 'text', 'text' => $instruction],
            ]]],
            'outputConfig' => ['effort' => $effort ?? (string) config('displanery.ai.legal_reading_effort', 'low'),
                'format' => ['type' => 'json_schema', 'schema' => [
                    'type' => 'object', 'properties' => ['text' => ['type' => 'string'], 'illegible' => ['type' => 'boolean']],
                    'required' => ['text', 'illegible'], 'additionalProperties' => false,
                ]]],
        ];
    }
}
