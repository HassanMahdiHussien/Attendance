<?php

use App\Support\Dates\ReportedDate;
use Carbon\CarbonImmutable;

test('reported dates use the sent date for relative words and weekdays', function (): void {
    $sent = CarbonImmutable::parse('2026-10-21 15:00', 'Asia/Baghdad');
    foreach (['21-10-2026' => '2026-10-21', '21/10/2026' => '2026-10-21',
        '2026-10-21' => '2026-10-21', 'October 20, 2026' => '2026-10-20',
        '20 تشرين الأول 2026' => '2026-10-20', '20 أكتوبر 2026' => '2026-10-20',
        'today' => '2026-10-21', 'yesterday' => '2026-10-20',
        'day before yesterday' => '2026-10-19', 'أول أمس' => '2026-10-19',
        'البارحة' => '2026-10-20', 'Monday' => '2026-10-19'] as $input => $expected) {
        expect(ReportedDate::parse($input, $sent)?->toDateString())->toBe($expected);
    }
    expect(ReportedDate::parse('31-02-2026', $sent))->toBeNull()
        ->and(ReportedDate::parse('22-10-2026', $sent))->toBeNull()
        ->and(ReportedDate::parse('20-10-2026 09:30', $sent)?->format('H:i'))->toBe('09:30');
});

test('an evening time is not read as morning, and «الخامس» is not yesterday', function (): void {
    $sent = CarbonImmutable::parse('2026-10-21 15:00', 'Asia/Baghdad');
    expect(ReportedDate::parse('Thursday, October 8, 2026, at approximately 9:30 PM', $sent)?->format('Y-m-d H:i'))->toBe('2026-10-08 21:30')
        ->and(ReportedDate::parse('8 تشرين الأول 2026 الساعة 9:30 مساءً', $sent)?->format('H:i'))->toBe('21:30')
        ->and(ReportedDate::parse('20-10-2026 7:15 صباحاً', $sent)?->format('H:i'))->toBe('07:15')
        ->and(ReportedDate::parse('في الخامس من الشهر', $sent))->toBeNull();
});
