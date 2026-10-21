<?php

use Carbon\Carbon;

test('the application clock follows Baghdad at the year boundary', function (): void {
    expect(config('app.timezone'))->toBe('Asia/Baghdad');
    Carbon::setTestNow(Carbon::parse('2026-12-31 21:30:00', 'UTC'));
    expect(now()->year)->toBe(2027);
    Carbon::setTestNow();
});
