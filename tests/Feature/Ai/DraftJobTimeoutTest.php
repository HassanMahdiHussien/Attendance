<?php

use App\Jobs\DraftDocumentWithAi;

test('a drafting job has time for a long bilingual draft but never outlives the queue retry window', function (): void {
    $timeout = (new ReflectionClass(DraftDocumentWithAi::class))->getDefaultProperties()['timeout'];

    expect($timeout)->toBeGreaterThanOrEqual(900)
        ->and($timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});
