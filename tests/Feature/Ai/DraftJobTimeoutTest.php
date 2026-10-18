<?php

use App\Ai\SdkClaudeClient;
use App\Jobs\DraftDocumentWithAi;

test('a drafting job has time for a long bilingual draft but never outlives the queue retry window', function (): void {
    $timeout = (new ReflectionClass(DraftDocumentWithAi::class))->getDefaultProperties()['timeout'];

    expect($timeout)->toBeGreaterThanOrEqual(900)
        ->and($timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

test('size keywords the structured-output API rejects are stripped from the schema sent to it', function (): void {
    $schema = ['type' => 'object', 'properties' => [
        'items' => ['type' => 'array', 'maxItems' => 4, 'minItems' => 1, 'items' => ['type' => 'string', 'maxLength' => 60]],
        'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
    ], 'required' => ['items', 'kind'], 'additionalProperties' => false];

    expect(SdkClaudeClient::apiSchema($schema))->toBe(['type' => 'object', 'properties' => [
        'items' => ['type' => 'array', 'items' => ['type' => 'string']],
        'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
    ], 'required' => ['items', 'kind'], 'additionalProperties' => false]);
});
