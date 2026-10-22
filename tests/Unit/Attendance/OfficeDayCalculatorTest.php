<?php

use App\Services\Attendance\OfficeDayCalculator;

function officeEmployee(array $overrides = []): array
{
    return array_replace([
        'work_pattern' => 'office', 'hire_date' => '2020-01-01', 'branch_id' => 1,
        'rest_days' => null, 'schedule_start' => null, 'schedule_end' => null,
        'exit_status' => 'none', 'last_working_day' => null,
    ], $overrides);
}

function officeRules(array $overrides = []): array
{
    return array_replace([
        'id' => 7, 'version' => 1, 'office_start' => '08:00:00', 'flex_minutes' => 60,
        'workday_minutes' => 540, 'min_presence_minutes' => 480,
        'close_grace_minutes' => 120, 'exits_count_toward_balance' => false,
        'office_rest_days' => [5, 6],
    ], $overrides);
}

function officePunch(string $time, string $role, int $id = 1): array
{
    return ['id' => $id, 'punched_at' => '2026-10-19 '.$time, 'role' => $role, 'status' => 'used'];
}

function officeDay(array $employee = [], array $policy = [], array $punches = [], array $manual = [], array $decisions = [], bool $covered = true, bool $outage = false, string $now = '2026-10-19 23:00:00'): array
{
    return (new OfficeDayCalculator)->calculate(officeEmployee($employee), officeRules($policy), $punches, $manual, $decisions, $covered, $outage, '2026-10-19', $now);
}

test('arrival boundaries determine required leave and lateness', function (string $arrival, string $departure, string $required, int $late, int $early): void {
    $day = officeDay(punches: [officePunch($arrival, 'check_in'), officePunch($departure, 'check_out', 2)]);

    expect($day['status'])->toBe('present')
        ->and($day['required_leave_at'])->toBe('2026-10-19 '.$required)
        ->and($day['late_minutes'] ?? 0)->toBe($late)
        ->and($day['early_minutes'])->toBe($early);
})->with([
    ['08:00:00', '17:00:00', '17:00:00', 0, 0],
    ['08:30:00', '17:30:00', '17:30:00', 0, 0],
    ['09:00:00', '18:00:00', '18:00:00', 0, 0],
    ['09:15:00', '18:00:00', '18:00:00', 15, 0],
    ['09:15:00', '17:50:00', '18:00:00', 15, 10],
    ['07:30:00', '17:00:00', '17:00:00', 0, 0],
    ['08:00:00', '16:59:00', '17:00:00', 0, 1],
    ['09:00:00', '17:59:00', '18:00:00', 0, 1],
]);

test('employee schedule overrides shift or derive the start and reject reversed ranges', function (): void {
    $onlyStart = officeDay(employee: ['schedule_start' => '10:00:00'], punches: [officePunch('10:30:00', 'check_in'), officePunch('19:30:00', 'check_out', 2)]);
    $onlyEnd = officeDay(employee: ['schedule_end' => '19:00:00'], punches: [officePunch('10:30:00', 'check_in'), officePunch('19:30:00', 'check_out', 2)]);
    $both = officeDay(employee: ['schedule_start' => '10:00:00', 'schedule_end' => '18:00:00'], punches: [officePunch('10:30:00', 'check_in'), officePunch('18:30:00', 'check_out', 2)]);
    $invalid = officeDay(employee: ['schedule_start' => '18:00:00', 'schedule_end' => '10:00:00']);

    expect($onlyStart['required_leave_at'])->toBe('2026-10-19 19:30:00')
        ->and($onlyEnd['required_leave_at'])->toBe('2026-10-19 19:30:00')
        ->and($both['required_leave_at'])->toBe('2026-10-19 18:30:00')
        ->and($invalid['status'])->toBe('pattern_missing')
        ->and($invalid['inputs']['reason'])->toBe('invalid_schedule');
});

test('shifted flexibility has the same exact-minute boundaries', function (): void {
    $atWindow = officeDay(employee: ['schedule_start' => '10:00:00'], punches: [officePunch('11:00:00', 'check_in'), officePunch('20:00:00', 'check_out', 2)]);
    $afterWindow = officeDay(employee: ['schedule_start' => '10:00:00'], punches: [officePunch('11:15:00', 'check_in'), officePunch('19:50:00', 'check_out', 2)]);

    expect($atWindow['late_minutes'])->toBe(0)
        ->and($atWindow['early_minutes'])->toBe(0)
        ->and($afterWindow['late_minutes'])->toBe(15)
        ->and($afterWindow['required_leave_at'])->toBe('2026-10-19 20:00:00')
        ->and($afterWindow['early_minutes'])->toBe(10);
});

test('multiple intervals show elapsed, presence and exits without counting exits unless enabled', function (): void {
    $punches = [
        officePunch('08:30:00', 'check_in'), officePunch('12:00:00', 'check_out', 2),
        officePunch('13:00:00', 'check_in', 3), officePunch('17:30:00', 'check_out', 4),
    ];
    $normal = officeDay(punches: $punches);
    $counted = officeDay(policy: ['exits_count_toward_balance' => true], punches: $punches);

    expect($normal['elapsed_minutes'])->toBe(540)
        ->and($normal['presence_minutes'])->toBe(480)
        ->and($normal['exit_minutes'])->toBe(60)
        ->and($normal['short_minutes'])->toBe(0)
        ->and($normal['balance_minutes'])->toBe(0)
        ->and($counted['balance_minutes'])->toBe(60)
        ->and($normal['exits'])->toHaveCount(1);
});

test('lateness, internal exits and early departure occupy distinct minute spans', function (): void {
    $day = officeDay(policy: ['exits_count_toward_balance' => true], punches: [
        officePunch('09:15:00', 'check_in'), officePunch('12:00:00', 'check_out', 2),
        officePunch('13:00:00', 'check_in', 3), officePunch('17:50:00', 'check_out', 4),
    ]);

    expect($day['late_minutes'])->toBe(15)
        ->and($day['exit_minutes'])->toBe(60)
        ->and($day['early_minutes'])->toBe(10)
        ->and($day['balance_minutes'])->toBe(85);
});

test('short hours remain separate from the balance', function (): void {
    $day = officeDay(punches: [officePunch('09:15:00', 'check_in'), officePunch('17:50:00', 'check_out', 2)]);

    expect($day['presence_minutes'])->toBe(515)
        ->and($day['late_minutes'])->toBe(15)
        ->and($day['early_minutes'])->toBe(10)
        ->and($day['balance_minutes'])->toBe(25)
        ->and($day['short_minutes'])->toBe(0);

    $short = officeDay(punches: [officePunch('09:15:00', 'check_in'), officePunch('16:00:00', 'check_out', 2)]);
    expect($short['short_minutes'])->toBe(75)
        ->and($short['balance_minutes'])->toBe(135);
});

test('unmatched punches keep evidence but have no balance', function (): void {
    $onlyIn = officeDay(punches: [officePunch('09:15:00', 'check_in')]);
    $onlyOut = officeDay(punches: [officePunch('17:00:00', 'check_out')]);

    expect($onlyIn['status'])->toBe('missing_punch')
        ->and($onlyIn['late_minutes'])->toBe(15)
        ->and($onlyIn['balance_minutes'])->toBeNull()
        ->and($onlyOut['status'])->toBe('missing_punch')
        ->and($onlyOut['first_in'])->toBeNull()
        ->and($onlyOut['balance_minutes'])->toBeNull();
});

test('a manual punch completes a day and its reversal restores the missing status', function (): void {
    $device = [officePunch('08:00:00', 'check_in')];
    $manual = [['id' => 10, 'punched_at' => '2026-10-19 17:00:00', 'role' => 'check_out']];
    $complete = officeDay(punches: $device, manual: $manual);
    $reversed = officeDay(punches: $device, manual: [...$manual, ['id' => 11, 'punched_at' => '2026-10-19 17:00:00', 'role' => 'check_out', 'reverses_id' => 10]]);

    expect($complete['status'])->toBe('present')
        ->and($complete['presence_minutes'])->toBe(540)
        ->and($reversed['status'])->toBe('missing_punch');
});

test('repeat punches are ignored and equal-time manual punches follow device punches', function (): void {
    $punches = [
        officePunch('08:00:00', 'check_in'),
        officePunch('08:01:00', 'check_in', 2),
        officePunch('12:00:00', 'check_out', 3),
    ];
    $punches[1]['status'] = 'repeat';
    $manual = [
        ['id' => 4, 'punched_at' => '2026-10-19 12:00:00', 'role' => 'check_in'],
        ['id' => 5, 'punched_at' => '2026-10-19 17:00:00', 'role' => 'check_out'],
    ];
    $day = officeDay(punches: $punches, manual: $manual);

    expect($day['status'])->toBe('present')
        ->and($day['intervals'])->toHaveCount(2)
        ->and($day['presence_minutes'])->toBe(540)
        ->and($day['exit_minutes'])->toBe(0);
});

test('confirmed absence takes precedence until withdrawn', function (): void {
    $punches = [officePunch('08:00:00', 'check_in'), officePunch('17:00:00', 'check_out', 2)];
    $confirmed = officeDay(punches: $punches, decisions: [['id' => 1, 'decision' => 'confirm_absence', 'created_at' => '2026-10-19 19:00:00']]);
    $withdrawn = officeDay(punches: $punches, decisions: [
        ['id' => 1, 'decision' => 'confirm_absence', 'created_at' => '2026-10-19 19:00:00'],
        ['id' => 2, 'decision' => 'withdraw_confirmation', 'created_at' => '2026-10-19 20:00:00'],
    ]);

    expect($confirmed['status'])->toBe('absent')
        ->and($confirmed['reviewed'])->toBeTrue()
        ->and($confirmed['first_in'])->not->toBeNull()
        ->and($withdrawn['status'])->toBe('present')
        ->and($withdrawn['reviewed'])->toBeFalse();
});

test('coverage and close grace both gate assessment', function (): void {
    $punches = [officePunch('08:00:00', 'check_in'), officePunch('17:00:00', 'check_out', 2)];

    expect(officeDay(punches: $punches, now: '2026-10-19 18:59:00')['status'])->toBe('pending')
        ->and(officeDay(punches: $punches, now: '2026-10-19 19:00:00')['status'])->toBe('present')
        ->and(officeDay(punches: $punches, covered: false)['status'])->toBe('pending')
        ->and(officeDay(now: '2026-10-19 19:59:00')['status'])->toBe('pending')
        ->and(officeDay(now: '2026-10-19 20:00:00')['status'])->toBe('absent');
});

test('eligibility and outage statuses take precedence over absence', function (): void {
    expect(officeDay(employee: ['rest_days' => [1]])['status'])->toBe('rest_day')
        ->and(officeDay(employee: ['hire_date' => '2026-10-20'])['status'])->toBe('not_employed')
        ->and(officeDay(employee: ['exit_status' => 'confirmed', 'last_working_day' => '2026-10-18'])['status'])->toBe('not_employed')
        ->and(officeDay(employee: ['exit_status' => 'confirmed', 'last_working_day' => '2026-10-19'])['status'])->toBe('absent')
        ->and(officeDay(employee: ['work_pattern' => null])['status'])->toBe('pattern_missing')
        ->and(officeDay(outage: true)['status'])->toBe('device_outage');
});

test('the policy defaults to Friday and Saturday rest days', function (): void {
    $calculator = new OfficeDayCalculator;

    foreach (['2026-10-23', '2026-10-24'] as $date) {
        $day = $calculator->calculate(officeEmployee(), officeRules(), [], [], [], true, false, $date, $date.' 23:00:00');
        expect($day['status'])->toBe('rest_day');
    }
});
