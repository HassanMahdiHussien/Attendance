<?php

use App\Services\Leave\LeaveDistributor;

test('two and a half days occupy three working days', function (): void {
    $result = (new LeaveDistributor)->distribute(
        ['start_date' => '2026-10-18', 'end_date' => '2026-10-20', 'duration_days' => 2.5],
        [], [['effective_from' => '2000-01-01', 'version' => 1, 'office_rest_days' => [5, 6]]],
    );
    expect(array_column($result['days'], 'minutes'))->toBe([480, 480, 240]);
});

test('employee rest days override policy and a mismatch needs confirmation', function (): void {
    $request = ['start_date' => '2026-10-18', 'end_date' => '2026-10-20', 'duration_days' => 1.0];
    $versions = [['id' => 1, 'effective_from' => '2026-10-18', 'changes' => ['rest_days' => [1, 5, 6]]]];
    $distributor = new LeaveDistributor;
    $unconfirmed = $distributor->distribute($request, $versions, []);
    $confirmed = $distributor->distribute($request, $versions, [], ['mode' => 'from_start']);
    expect($unconfirmed['flag'])->toBe('duration_mismatch')
        ->and(array_column($confirmed['days'], 'work_date'))->toBe(['2026-10-18']);
});

test('back dated rest day versions affect only their effective dates', function (): void {
    $result = (new LeaveDistributor)->distribute(
        ['start_date' => '2026-10-18', 'end_date' => '2026-10-20', 'duration_days' => 2.0],
        [['id' => 1, 'effective_from' => '2026-10-19', 'changes' => ['rest_days' => [2, 5, 6]]]],
        [],
    );
    expect(array_column($result['days'], 'work_date'))->toBe(['2026-10-18', '2026-10-19']);
});

test('the first matching count wins while leave minutes stay on employee working dates', function (): void {
    $distributor = new LeaveDistributor;
    $policies = [['effective_from' => '2000-01-01', 'version' => 1, 'office_rest_days' => [5, 6]]];
    $employee = $distributor->distribute(['start_date' => '2026-10-18', 'end_date' => '2026-10-22', 'duration_days' => 5.0], [], $policies);
    $leaveCalendar = $distributor->distribute(['start_date' => '2026-10-16', 'end_date' => '2026-10-21', 'duration_days' => 5.0], [], $policies);
    $fiveOfficeDays = $distributor->distribute(['start_date' => '2026-10-17', 'end_date' => '2026-10-22', 'duration_days' => 6.0], [], $policies);
    $calendar = $distributor->distribute(['start_date' => '2026-10-16', 'end_date' => '2026-10-20', 'duration_days' => 5.0], [], $policies);

    expect($employee['counting_method'])->toBe('employee')
        ->and($leaveCalendar['counting_method'])->toBe('leave_calendar')
        ->and(array_column($leaveCalendar['days'], 'work_date'))->toBe([
            '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21',
        ])
        ->and(array_column($leaveCalendar['days'], 'minutes'))->toBe([480, 480, 480, 480])
        ->and($fiveOfficeDays['counting_method'])->toBe('leave_calendar')
        ->and(array_column($fiveOfficeDays['days'], 'work_date'))->toBe([
            '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22',
        ])
        ->and($calendar['counting_method'])->toBe('calendar')
        ->and(array_column($calendar['days'], 'work_date'))->toBe(['2026-10-18', '2026-10-19', '2026-10-20']);
});

test('a half day counted by the leave calendar does not move onto a rest day', function (): void {
    $result = (new LeaveDistributor)->distribute(
        ['start_date' => '2026-10-16', 'end_date' => '2026-10-17', 'duration_days' => 0.5],
        [], [['effective_from' => '2000-01-01', 'version' => 1, 'office_rest_days' => [5, 6]]],
    );
    expect($result['counting_method'])->toBe('leave_calendar')
        ->and($result['days'])->toBe([]);
});

test('a half day validated by the leave calendar uses only the first employee working date', function (): void {
    $result = (new LeaveDistributor)->distribute(
        ['start_date' => '2026-10-16', 'end_date' => '2026-10-17', 'duration_days' => 0.5],
        [['id' => 1, 'effective_from' => '2026-10-16', 'changes' => ['rest_days' => [7]]]], [],
    );
    expect($result['counting_method'])->toBe('leave_calendar')
        ->and($result['days'])->toBe([['work_date' => '2026-10-16', 'minutes' => 240]]);
});

test('unexplained duration remains a mismatch without distributed days', function (): void {
    $result = (new LeaveDistributor)->distribute(
        ['start_date' => '2026-10-18', 'end_date' => '2026-10-20', 'duration_days' => 1.0], [], [],
    );
    expect($result['flag'])->toBe('duration_mismatch')
        ->and($result['counting_method'])->toBeNull()
        ->and($result['days'])->toBe([]);
});
