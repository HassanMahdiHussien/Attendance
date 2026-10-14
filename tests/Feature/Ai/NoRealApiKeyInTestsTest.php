<?php

// A developer's real key in .env must never reach tests: queued AI jobs run synchronously here and would spend money.
test('the test environment never carries a real Anthropic API key', function (): void {
    expect(config('services.anthropic.key'))->toBe('');
});
