<?php

use App\Services\Attendance\OfficeDayCalculator;
use App\Services\Attendance\ShiftDayCalculator;

function shiftEmployee(array $values = []): array
{
    return array_replace([
        'work_pattern' => 'shift', 'hire_date' => '2020-01-01', 'branch_id' => 1,
        'rest_days' => [], 'exit_status' => 'none', 'last_working_day' => null,
    ], $values);
}

function shiftPolicy(array $values = []): array
{
    return array_replace([
        'id' => 7, 'version' => 1, 'shift_starts' => ['00:00', '08:00', '16:00'],
        'shift_minutes' => 480, 'shift_rest_days' => [5],
        'shift_min_presence_minutes' => 480, 'security_min_presence_minutes' => 480,
        'absence_minutes' => 480, 'inference_tolerance_minutes' => 120,
        'close_grace_minutes' => 120, 'exits_count_toward_balance' => false,
    ], $values);
}

function shiftPunch(string $timestamp, string $role, int $id = 1): array
{
    return ['id' => $id, 'punched_at' => $timestamp, 'role' => $role, 'status' => 'used'];
}

function shiftDay(array $punches = [], array $employee = [], array $policy = [], array $manual = [], array $decisions = [], int $leave = 0, bool $covered = true, bool $outage = false, string $now = '2026-10-20 04:00:00'): array
{
    return (new ShiftDayCalculator)->calculate(
        shiftEmployee($employee), shiftPolicy($policy), $punches, $manual, $decisions,
        $covered, $outage, '2026-10-19', $now, $leave,
    );
}

test('each shift is inferred and the evening checkout remains on the start date', function (string $start, string $end, string $required): void {
    $day = shiftDay([shiftPunch($start, 'check_in'), shiftPunch($end, 'check_out', 2)]);

    expect($day['status'])->toBe('present')
        ->and($day['shift_source'])->toBe('inferred')
        ->and($day['shift_confidence'])->toBe('clear')
        ->and($day['required_leave_at'])->toBe($required)
        ->and($day['presence_minutes'])->toBe(480);
})->with([
    ['2026-10-19 00:00:00', '2026-10-19 08:00:00', '2026-10-19 08:00:00'],
    ['2026-10-19 08:00:00', '2026-10-19 16:00:00', '2026-10-19 16:00:00'],
    ['2026-10-19 16:00:00', '2026-10-20 00:00:00', '2026-10-20 00:00:00'],
]);

test('shift arrival has no grace and an early departure does not cancel lateness', function (): void {
    $day = shiftDay([shiftPunch('2026-10-19 08:01:00', 'check_in'), shiftPunch('2026-10-19 15:59:00', 'check_out', 2)]);

    expect($day['late_minutes'])->toBe(1)
        ->and($day['early_minutes'])->toBe(1)
        ->and($day['balance_minutes'])->toBe(2)
        ->and($day['short_minutes'])->toBe(2);
});

test('equal scores and no fitting shift leave late and early unconfirmed', function (): void {
    $tie = shiftDay([shiftPunch('2026-10-19 04:00:00', 'check_in'), shiftPunch('2026-10-19 12:00:00', 'check_out', 2)], policy: ['inference_tolerance_minutes' => 240]);
    $none = shiftDay([shiftPunch('2026-10-19 11:00:00', 'check_in'), shiftPunch('2026-10-19 13:00:00', 'check_out', 2)]);

    expect($tie['status'])->toBe('shift_unresolved')
        ->and($tie['late_minutes'])->toBeNull()
        ->and($tie['early_minutes'])->toBeNull()
        ->and($none['status'])->toBe('shift_unresolved')
        ->and($none['balance_minutes'])->toBeNull();
});

test('half-day leave explains a late start only with supporting punch evidence', function (): void {
    $day = shiftDay([shiftPunch('2026-10-19 12:00:00', 'check_in'), shiftPunch('2026-10-19 16:00:00', 'check_out', 2)], leave: 240);
    $early = shiftDay([shiftPunch('2026-10-19 08:00:00', 'check_in'), shiftPunch('2026-10-19 12:00:00', 'check_out', 2)], leave: 240);

    expect($day['status'])->toBe('present')
        ->and($day['shift_start'])->toBe('08:00:00')
        ->and($day['shift_confidence'])->toBe('leave_explained')
        ->and($day['late_minutes'])->toBe(240)
        ->and($early['shift_confidence'])->toBe('leave_explained')
        ->and($early['early_minutes'])->toBe(240);
});

test('half-day leave does not choose between two equally explained edges', function (): void {
    $day = shiftDay([
        shiftPunch('2026-10-19 12:00:00', 'check_in'),
        shiftPunch('2026-10-19 16:00:00', 'check_out', 2),
    ], policy: ['shift_starts' => ['08:00', '12:00']], leave: 240);

    expect($day['status'])->toBe('shift_unresolved')
        ->and($day['shift_confidence'])->toBe('unresolved');
});

test('single punches keep missing-punch review and a known start can show lateness', function (): void {
    $in = shiftDay([shiftPunch('2026-10-19 08:01:00', 'check_in')]);
    $out = shiftDay([shiftPunch('2026-10-19 16:00:00', 'check_out')]);
    $unknown = shiftDay([shiftPunch('2026-10-19 04:00:00', 'check_in')]);

    expect($in['status'])->toBe('missing_punch')
        ->and($in['late_minutes'])->toBe(1)
        ->and($in['balance_minutes'])->toBeNull()
        ->and($out['status'])->toBe('missing_punch')
        ->and($out['shift_start'])->toBe('08:00:00')
        ->and($unknown['status'])->toBe('missing_punch')
        ->and($unknown['shift_start'])->toBeNull();
});

test('absence has no invented shift and waits until next-day two oclock', function (): void {
    $pending = shiftDay(now: '2026-10-20 01:59:00');
    $absent = shiftDay(now: '2026-10-20 02:00:00');

    expect($pending['status'])->toBe('pending')
        ->and($absent['status'])->toBe('absent')
        ->and($absent['shift_source'])->toBe('unknown')
        ->and($absent['shift_start'])->toBeNull()
        ->and($absent['inputs']['absence_minutes'])->toBe(480)
        ->and($absent['required_leave_at'])->toBe('2026-10-20 00:00:00');
});

test('HR choice overrides inference and confirmed absence takes precedence', function (): void {
    $punches = [shiftPunch('2026-10-19 08:00:00', 'check_in'), shiftPunch('2026-10-19 16:00:00', 'check_out', 2)];
    $picked = shiftDay($punches, decisions: [['id' => 1, 'decision' => 'pick_shift', 'shift_start' => '16:00:00']]);
    $confirmed = shiftDay($punches, decisions: [['id' => 1, 'decision' => 'confirm_absence']]);

    expect($picked['shift_start'])->toBe('16:00:00')
        ->and($picked['shift_source'])->toBe('hr')
        ->and($confirmed['status'])->toBe('absent')
        ->and($confirmed['reviewed'])->toBeTrue();
});

test('presence counts intervals without deducting the declared break and exits are optional balance', function (): void {
    $punches = [
        shiftPunch('2026-10-19 08:00:00', 'check_in'), shiftPunch('2026-10-19 12:00:00', 'check_out', 2),
        shiftPunch('2026-10-19 12:30:00', 'check_in', 3), shiftPunch('2026-10-19 16:00:00', 'check_out', 4),
    ];
    $normal = shiftDay($punches);
    $counted = shiftDay($punches, policy: ['exits_count_toward_balance' => true]);

    expect($normal['elapsed_minutes'])->toBe(480)
        ->and($normal['presence_minutes'])->toBe(450)
        ->and($normal['short_minutes'])->toBe(30)
        ->and($normal['exit_minutes'])->toBe(30)
        ->and($normal['balance_minutes'])->toBe(0)
        ->and($counted['balance_minutes'])->toBe(30);
});

test('manual completion, rest days, missing security rest day and close gate', function (): void {
    $manual = shiftDay([shiftPunch('2026-10-19 08:00:00', 'check_in')], manual: [
        shiftPunch('2026-10-19 16:00:00', 'check_out', 10),
    ]);
    $security = shiftDay(employee: ['work_pattern' => 'security', 'rest_days' => null]);
    $rest = shiftDay(employee: ['rest_days' => [1]]);
    $pending = shiftDay([shiftPunch('2026-10-19 08:00:00', 'check_in'), shiftPunch('2026-10-19 16:00:00', 'check_out', 2)], now: '2026-10-19 17:59:00');
    $uncovered = shiftDay([shiftPunch('2026-10-19 08:00:00', 'check_in'), shiftPunch('2026-10-19 16:00:00', 'check_out', 2)], covered: false);
    $outage = shiftDay(outage: true);

    expect($manual['status'])->toBe('present')
        ->and($security['status'])->toBe('pattern_missing')
        ->and($security['inputs']['reason'])->toBe('rest_day_missing')
        ->and($rest['status'])->toBe('rest_day')
        ->and($pending['status'])->toBe('pending')
        ->and($uncovered['status'])->toBe('pending')
        ->and($outage['status'])->toBe('device_outage');
});

test('additive policy values leave office calculation unchanged', function (): void {
    $employee = ['work_pattern' => 'office', 'hire_date' => '2020-01-01', 'branch_id' => 1, 'rest_days' => []];
    $policy = [
        'id' => 1, 'version' => 1, 'office_start' => '08:00:00', 'flex_minutes' => 60,
        'workday_minutes' => 540, 'min_presence_minutes' => 480, 'close_grace_minutes' => 120,
        'office_rest_days' => [5, 6], 'exits_count_toward_balance' => false,
    ];
    $punches = [shiftPunch('2026-10-19 08:00:00', 'check_in'), shiftPunch('2026-10-19 17:00:00', 'check_out', 2)];
    $calculator = new OfficeDayCalculator;
    $before = $calculator->calculate($employee, $policy, $punches, [], [], true, false, '2026-10-19', '2026-10-19 20:00:00');
    $after = $calculator->calculate($employee, $policy + shiftPolicy(), $punches, [], [], true, false, '2026-10-19', '2026-10-19 20:00:00');

    expect($after)->toBe($before)
        ->and($before['short_minutes'])->toBe(0);
});
