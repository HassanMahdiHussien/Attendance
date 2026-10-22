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
