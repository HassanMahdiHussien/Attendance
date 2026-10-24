<?php

use App\Services\Attendance\OvertimeCalculator;

function overtimeDay(string $date, string $status, array $intervals, ?string $required = null): array
{
    return [
        'work_date' => $date, 'status' => $status, 'intervals' => $intervals,
        'first_in' => $intervals[0]['from'] ?? null,
        'last_out' => $intervals[array_key_last($intervals)]['to'] ?? null,
        'required_leave_at' => $required, 'presence_minutes' => array_sum(array_map(
            fn (array $interval): int => intdiv(strtotime($interval['to']) - strtotime($interval['from']), 60), $intervals,
        )),
        'intervals_complete' => true, 'assessment_closed' => true,
    ];
}

function overtimeInterval(string $from, string $to): array
{
    return ['from' => $from, 'to' => $to];
}

test('office overtime uses interval intersections, minimum and downward step', function (string $lastOut, int $expected): void {
    $day = overtimeDay('2026-10-19', 'present', [overtimeInterval('2026-10-19 09:00:00', '2026-10-19 '.$lastOut)], '2026-10-19 18:00:00');
    $result = (new OvertimeCalculator)->calculate($day, [], ['work_pattern' => 'office'], []);

    expect($result['overtime_minutes'])->toBe($expected)
        ->and($result['overtime_basis'])->toBe('after_end');
})->with([
    '29 minutes' => ['18:29:00', 0],
    '44 minutes' => ['18:44:00', 30],
    '61 minutes' => ['19:01:00', 60],
]);

test('an exit after the required end does not count as worked overtime', function (): void {
    $day = overtimeDay('2026-10-19', 'present', [
        overtimeInterval('2026-10-19 08:00:00', '2026-10-19 18:15:00'),
        overtimeInterval('2026-10-19 18:45:00', '2026-10-19 19:15:00'),
    ], '2026-10-19 18:00:00');

    expect((new OvertimeCalculator)->calculate($day, [], ['work_pattern' => 'office'], [])['overtime_minutes'])->toBe(45);
});

test('evening and midnight shifts use the required end on the owning date', function (string $date, string $start, string $end, string $required): void {
    $day = overtimeDay($date, 'present', [overtimeInterval($start, $end)], $required);
    $day['shift_start'] = substr($start, 11);
    $result = (new OvertimeCalculator)->calculate($day, [], ['work_pattern' => 'shift'], []);

    expect($result['overtime_minutes'])->toBe(45);
})->with([
    'evening' => ['2026-10-19', '2026-10-19 16:00:00', '2026-10-20 00:45:00', '2026-10-20 00:00:00'],
    'midnight' => ['2026-10-20', '2026-10-20 00:00:00', '2026-10-20 08:45:00', '2026-10-20 08:00:00'],
]);

test('early arrival is a marker and uses the schedule override', function (): void {
    $day = overtimeDay('2026-10-19', 'present', [overtimeInterval('2026-10-19 06:45:00', '2026-10-19 17:00:00')], '2026-10-19 17:00:00');
    $result = (new OvertimeCalculator)->calculate($day, [], ['work_pattern' => 'office', 'schedule_start' => '07:30:00'], []);

    expect($result['early_arrival_minutes'])->toBe(45)->and($result['overtime_minutes'])->toBe(0);
});

test('unmatched or unfinished days never earn overtime', function (string $status, bool $complete, bool $closed): void {
    $day = overtimeDay('2026-10-16', $status, [overtimeInterval('2026-10-16 08:00:00', '2026-10-16 16:00:00')]);
    $day['intervals_complete'] = $complete;
    $day['assessment_closed'] = $closed;

    expect((new OvertimeCalculator)->calculate($day, [], ['work_pattern' => 'office'], [])['overtime_minutes'])->toBeNull();
})->with([
    ['missing_punch', true, true], ['rest_day_worked', false, true], ['rest_day_worked', true, false],
    ['pending', true, true], ['shift_unresolved', true, true],
]);

test('rest-day thresholds and historical compensatory eligibility', function (string $date, string $pattern, array $employeeRest, int $worked, ?float $quantity): void {
    $day = overtimeDay($date, 'rest_day_worked', [overtimeInterval($date.' 08:00:00', date('Y-m-d H:i:s', strtotime($date.' 08:00:00') + $worked * 60))]);
    $result = (new OvertimeCalculator)->calculate($day, [], ['work_pattern' => $pattern, 'rest_days' => $employeeRest], []);

    expect($result['overtime_minutes'])->toBe(intdiv($worked, 15) * 15)
        ->and($result['entitlement']['quantity'] ?? null)->toBe($quantity);
})->with([
    'office Friday under four hours' => ['2026-10-16', 'office', [5, 6], 210, null],
    'office Friday half' => ['2026-10-16', 'office', [5, 6], 240, 0.5],
    'office Friday full' => ['2026-10-16', 'office', [5, 6], 480, 1.0],
    'office Saturday no entitlement' => ['2026-10-17', 'office', [5, 6], 480, null],
    'shift Friday half' => ['2026-10-16', 'shift', [5], 240, 0.5],
    'security individual Saturday' => ['2026-10-17', 'security', [6], 480, 1.0],
    'Friday removed from employee rest days' => ['2026-10-16', 'office', [6], 480, null],
]);

test('a holiday on an eligible rest day earns one overtime amount and one entitlement', function (): void {
    $day = overtimeDay('2026-10-16', 'rest_day_worked', [overtimeInterval('2026-10-16 08:00:00', '2026-10-16 12:07:00')]);
    $result = (new OvertimeCalculator)->calculate($day, [], ['work_pattern' => 'office', 'rest_days' => [5, 6]], [12]);

    expect($result['overtime_minutes'])->toBe(240)
        ->and($result['overtime_basis'])->toBe('holiday')
        ->and($result['entitlement']['quantity'])->toBe(0.5)
        ->and($result['entitlement']['holiday'])->toBeTrue();
});

test('a holiday on a working day earns overtime without a rest-day entitlement', function (): void {
    $day = overtimeDay('2026-10-19', 'holiday_worked', [overtimeInterval('2026-10-19 08:00:00', '2026-10-19 12:00:00')]);
    $result = (new OvertimeCalculator)->calculate($day, [], ['work_pattern' => 'office', 'rest_days' => [5, 6]], [14]);

    expect($result['overtime_minutes'])->toBe(240)
        ->and($result['overtime_basis'])->toBe('holiday')
        ->and($result['entitlement'])->toBeNull();
});
