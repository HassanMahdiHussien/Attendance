<?php

use App\Services\Attendance\OfficeDayCalculator;
use App\Services\Attendance\ShiftDayCalculator;

test('office rest-day punches retain presence without attendance debt', function (): void {
    $day = app(OfficeDayCalculator::class)->calculate([
        'work_pattern' => 'office', 'hire_date' => '2020-01-01', 'branch_id' => 1,
        'rest_days' => [1], 'exit_status' => 'none', 'schedule_start' => null, 'schedule_end' => null,
    ], [
        'office_start' => '08:00', 'flex_minutes' => 60, 'workday_minutes' => 540,
        'office_rest_days' => [5, 6], 'close_grace_minutes' => 120,
    ], [
        ['id' => 1, 'punched_at' => '2026-10-19 08:00:00', 'role' => 'check_in', 'status' => 'used'],
        ['id' => 2, 'punched_at' => '2026-10-19 12:00:00', 'role' => 'check_out', 'status' => 'used'],
    ], [], [], false, false, '2026-10-19', '2026-10-19 12:01:00');

    expect($day['status'])->toBe('rest_day_worked')
        ->and($day['presence_minutes'])->toBe(240)
        ->and($day['late_minutes'])->toBe(0)
        ->and($day['balance_minutes'])->toBe(0);
});

test('shift and security rest-day punches retain elapsed and presence across midnight', function (string $pattern): void {
    $day = app(ShiftDayCalculator::class)->calculate([
        'work_pattern' => $pattern, 'hire_date' => '2020-01-01', 'branch_id' => 1,
        'rest_days' => [1], 'exit_status' => 'none',
    ], [
        'shift_starts' => ['00:00', '08:00', '16:00'], 'shift_minutes' => 480,
        'shift_rest_days' => [5], 'close_grace_minutes' => 120,
    ], [
        ['id' => 1, 'punched_at' => '2026-10-19 16:00:00', 'role' => 'check_in', 'status' => 'used'],
        ['id' => 2, 'punched_at' => '2026-10-20 00:00:00', 'role' => 'check_out', 'status' => 'used'],
    ], [], [], false, false, '2026-10-19', '2026-10-20 00:01:00');

    expect($day['status'])->toBe('rest_day_worked')
        ->and($day['presence_minutes'])->toBe(480)
        ->and($day['elapsed_minutes'])->toBe(480)
        ->and($day['balance_minutes'])->toBe(0);
})->with(['shift', 'security']);
