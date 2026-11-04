<?php

use App\Services\Leave\LeaveDescriptionDates;
use App\Services\Leave\LeaveDescriptionReader;

test('worked dates are day first and never invented', function (string $text, array $expected, bool $inferred): void {
    $result = app(LeaveDescriptionDates::class)->dates($text, '2026-10-15');
    expect($result['dates'])->toBe($expected)
        ->and($result['inferred_year'])->toBe($inferred);
})->with([
    ['compensation 09.10.2026', ['2026-10-09'], false],
    ['compensation 25/9/26', ['2026-09-25'], false],
    ['compensation 09-10-2026', ['2026-10-09'], false],
    ['compensation 25/9', ['2026-09-25'], true],
    ['compensation 09.10', ['2026-10-09'], true],
    ['compensation 25/12', ['2025-12-25'], true],
    ['compensation 31/2/2026', [], false],
]);

test('named and numeric months produce whole periods', function (string $text, string $from, string $to, bool $inferred): void {
    $result = app(LeaveDescriptionDates::class)->period($text, '2026-10-15');
    expect($result['from'])->toBe($from)
        ->and($result['to'])->toBe($to)
        ->and($result['inferred_year'])->toBe($inferred);
})->with([
    ['hours july and august 2026', '2026-07-01', '2026-08-31', false],
    ['hours july, august 2026', '2026-07-01', '2026-08-31', false],
    ['hours july & august 2026', '2026-07-01', '2026-08-31', false],
    ['hours jul and aug 2026', '2026-07-01', '2026-08-31', false],
    ['hours 7/2026 and 08.2026', '2026-07-01', '2026-08-31', false],
    ['hours 7-2026', '2026-07-01', '2026-07-31', false],
    ['hours september', '2026-09-01', '2026-09-30', true],
    ['hours november', '2025-11-01', '2025-11-30', true],
]);

test('a bare date uses the same accepted shapes', function (string $text): void {
    expect(app(LeaveDescriptionDates::class)->bareDate($text))->toBeTrue();
})->with(['09.10.2026', '25/9/26', '09-10-2026', '25/9']);

test('hourly words have boundaries', function (): void {
    expect(LeaveDescriptionReader::mentionsHourly('three days'))->toBeFalse()
        ->and(LeaveDescriptionReader::mentionsHourly('2 hours'))->toBeTrue()
        ->and(LeaveDescriptionReader::mentionsHourly('2 ساعات'))->toBeTrue();
});
