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

test('relative periods use the request start date', function (string $phrase, string $from, string $to): void {
    $period = app(LeaveDescriptionDates::class)->period('Hours '.$phrase, '2026-11-02');
    expect($period['from'])->toBe($from)->and($period['to'])->toBe($to)
        ->and($period['relative'])->toBeTrue();
})->with([
    ['last week', '2026-10-26', '2026-11-01'],
    ['LAST WEEK', '2026-10-26', '2026-11-01'],
    ['previous week', '2026-10-26', '2026-11-01'],
    ['past week', '2026-10-26', '2026-11-01'],
    ['الأسبوع الماضي', '2026-10-26', '2026-11-01'],
    ['الاسبوع الماضي', '2026-10-26', '2026-11-01'],
    ['الأسبوع السابق', '2026-10-26', '2026-11-01'],
    ['الاسبوع السابق', '2026-10-26', '2026-11-01'],
    ['الأسبوع الفائت', '2026-10-26', '2026-11-01'],
    ['الاسبوع الفائت', '2026-10-26', '2026-11-01'],
    ['this week', '2026-10-27', '2026-11-02'],
    ['هذا الأسبوع', '2026-10-27', '2026-11-02'],
    ['هذا الاسبوع', '2026-10-27', '2026-11-02'],
    ['yesterday', '2026-11-01', '2026-11-01'],
    ['أمس', '2026-11-01', '2026-11-01'],
    ['امس', '2026-11-01', '2026-11-01'],
    ['البارحة', '2026-11-01', '2026-11-01'],
    ['last month', '2026-10-01', '2026-10-31'],
    ['previous month', '2026-10-01', '2026-10-31'],
    ['الشهر الماضي', '2026-10-01', '2026-10-31'],
    ['الشهر السابق', '2026-10-01', '2026-10-31'],
]);

test('explicit periods win and relative periods require an anchor', function (): void {
    $dates = app(LeaveDescriptionDates::class);
    expect($dates->period('Hours last week, October 2025', '2026-11-02')['from'])->toBe('2025-10-01')
        ->and($dates->period('Hours last week, 09.10.2026', '2026-11-02')['from'])->toBe('2026-10-09')
        ->and($dates->period('Hours last week', null))->toBeNull();
});

test('hourly words have boundaries', function (): void {
    expect(LeaveDescriptionReader::mentionsHourly('three days'))->toBeFalse()
        ->and(LeaveDescriptionReader::mentionsHourly('2 hours'))->toBeTrue()
        ->and(LeaveDescriptionReader::mentionsHourly('2 ساعات'))->toBeTrue();
});
