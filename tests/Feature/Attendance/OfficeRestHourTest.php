<?php

use App\Services\Attendance\OfficeDayCalculator;
use App\Services\Attendance\OfficePolicy;

function officeRestDay(array $times): array
{
    $punches = [];
    foreach ($times as $time => $role) {
        $punches[] = ['punched_at' => '2026-10-19 '.$time, 'role' => $role];
    }

    return app(OfficeDayCalculator::class)->calculate(
        ['work_pattern' => 'office', 'hire_date' => '2020-01-01'],
        OfficePolicy::DEFAULTS, $punches, [], [], true, false, '2026-10-19', '2026-10-20 12:00:00',
    );
}

test('office rest hour leaves a full worked day without short time', function (): void {
    $day = officeRestDay(['08:29:00' => 'check_in', '12:50:00' => 'check_out',
        '13:55:00' => 'check_in', '17:53:00' => 'check_out']);

    expect($day['status'])->toBe('present')
        ->and($day['elapsed_minutes'])->toBe(564)
        ->and($day['presence_minutes'])->toBe(499)
        ->and($day['exit_minutes'])->toBe(65)
        ->and($day['short_minutes'])->toBe(0)
        ->and($day['late_minutes'])->toBe(0)
        ->and($day['early_minutes'])->toBe(0)
        ->and($day['balance_minutes'])->toBe(0)
        ->and($day['required_leave_at'])->toBe('2026-10-19 17:29:00');
});

test('office minimum presence and early departure remain separate', function (): void {
    $short = officeRestDay(['08:00:00' => 'check_in', '15:59:00' => 'check_out']);
    expect($short['presence_minutes'])->toBe(479)
        ->and($short['short_minutes'])->toBe(1)
        ->and($short['early_minutes'])->toBe(61);

    $early = officeRestDay(['08:00:00' => 'check_in', '16:30:00' => 'check_out']);
    expect($early['short_minutes'])->toBe(0)
        ->and($early['early_minutes'])->toBe(30);
});
