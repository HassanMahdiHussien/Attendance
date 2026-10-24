<?php

use App\Services\Leave\LeaveAllocator;

function leaveSource(string $id, string $date, int $minutes, string $request = 'FORM-1'): array
{
    return ['id' => $id, 'work_date' => $date, 'minutes' => $minutes, 'external_id' => $request];
}

function leaveTarget(int $id, string $date, string $kind, int $minutes): array
{
    return ['day_id' => $id, 'work_date' => $date, 'kind' => $kind, 'minutes' => $minutes];
}

test('four hours settle three and surplus credit covers later lateness', function (): void {
    $result = (new LeaveAllocator)->allocate(
        [leaveSource('a', '2026-10-19', 240)],
        [leaveTarget(1, '2026-10-19', 'early', 180), leaveTarget(2, '2026-10-20', 'late', 15)],
    );
    expect($result['allocations'])->toHaveCount(2)
        ->and($result['allocations'][0]['minutes'])->toBe(180)
        ->and($result['allocations'][1]['source_kind'])->toBe('credit')
        ->and($result['allocations'][1]['explanation']['request'])->toBe('FORM-1')
        ->and($result['credits'][0]['remaining'])->toBe(45);
});

test('half day on an absence leaves four hours unreconciled', function (): void {
    $result = (new LeaveAllocator)->allocate(
        [leaveSource('a', '2026-10-19', 240)], [leaveTarget(1, '2026-10-19', 'absence', 480)],
    );
    expect($result['remaining']['1|absence'])->toBe(240)->and($result['credits'])->toBe([]);
});

test('same day leave is spent before credit and targets are ordered by date and kind', function (): void {
    $result = (new LeaveAllocator)->allocate(
        [leaveSource('later', '2026-10-21', 240, 'B'), leaveSource('earlier', '2026-10-19', 240, 'A')],
        [leaveTarget(3, '2026-10-21', 'early', 20), leaveTarget(2, '2026-10-20', 'late', 15),
            leaveTarget(1, '2026-10-20', 'absence', 480)],
    );
    expect($result['allocations'][0]['target_day_id'])->toBe(3)
        ->and($result['allocations'][1]['target_kind'])->toBe('absence')
        ->and($result['allocations'][1]['explanation']['request'])->toBe('A');
});

test('a cancelled credit source is removed and another valid credit refills the target', function (): void {
    $allocator = new LeaveAllocator;
    $sources = [leaveSource('a', '2026-10-19', 240, 'A'), leaveSource('b', '2026-10-20', 240, 'B')];
    $targets = [leaveTarget(1, '2026-10-21', 'late', 15)];
    $before = $allocator->allocate($sources, $targets);
    $after = $allocator->allocate([$sources[1]], $targets);
    expect($before['allocations'][0]['explanation']['request'])->toBe('A')
        ->and($after['allocations'][0]['explanation']['request'])->toBe('B');
});

test('reversed deduction remains open and expiry blocks late use', function (): void {
    $allocator = new LeaveAllocator;
    $sources = [leaveSource('a', '2026-10-19', 240)];
    $targets = [leaveTarget(1, '2026-10-20', 'late', 15)];
    $blocked = $allocator->allocate($sources, $targets, ['2026-10-20|late' => true]);
    $expired = $allocator->allocate($sources, $targets, [], 0);
    expect($blocked['allocations'])->toBe([])->and($blocked['remaining']['1|late'])->toBe(15)
        ->and($expired['allocations'])->toBe([])->and($expired['credits'][0]['expires_on'])->toBe('2026-10-19');
});

test('leave on a present day with no balance raises a flag without making an allocation', function (): void {
    $result = (new LeaveAllocator)->allocate([leaveSource('a', '2026-10-19', 480)], [], [], null, ['2026-10-19']);
    expect($result['allocations'])->toBe([])
        ->and($result['flags'][0]['code'])->toBe('leave_with_attendance_nothing_to_reconcile');
});

test('leave on an unassessed day cannot create spendable credit', function (): void {
    $result = (new LeaveAllocator)->allocate(
        [leaveSource('a', '2026-10-19', 480)], [leaveTarget(1, '2026-10-20', 'late', 15)],
        creditEligibleDates: ['2026-10-20'],
    );
    expect($result['credits'])->toBe([])->and($result['allocations'])->toBe([])
        ->and($result['remaining']['1|late'])->toBe(15);
});

test('overlapping leave never spends a target minute twice', function (): void {
    $result = (new LeaveAllocator)->allocate(
        [leaveSource('a', '2026-10-19', 240, 'A'), leaveSource('b', '2026-10-19', 240, 'B')],
        [leaveTarget(1, '2026-10-19', 'absence', 300)],
    );
    expect(array_sum(array_column($result['allocations'], 'minutes')))->toBe(300)
        ->and($result['remaining']['1|absence'])->toBe(0)
        ->and(array_sum(array_column($result['credits'], 'minutes')))->toBe(180);
});

test('compensation leave covers its own day but never creates surplus credit', function (): void {
    $source = leaveSource('a', '2026-10-19', 240);
    $source['credit_allowed'] = false;
    $result = (new LeaveAllocator)->allocate([$source], [leaveTarget(1, '2026-10-19', 'late', 30)],
        creditEligibleDates: ['2026-10-19']);

    expect($result['allocations'])->toHaveCount(1)
        ->and($result['allocations'][0]['minutes'])->toBe(30)
        ->and($result['credits'])->toBe([]);
});

test('other approved leave retains surplus credit when its type permits it', function (): void {
    $source = leaveSource('a', '2026-10-19', 240);
    $source['credit_allowed'] = true;
    $result = (new LeaveAllocator)->allocate([$source], [leaveTarget(1, '2026-10-19', 'late', 30)],
        creditEligibleDates: ['2026-10-19']);

    expect($result['credits'])->toHaveCount(1)
        ->and($result['credits'][0]['minutes'])->toBe(210);
});

test('a present day applies leave to late then early then exit minutes', function (): void {
    $result = (new LeaveAllocator)->allocate(
        [leaveSource('a', '2026-10-19', 240)],
        [leaveTarget(1, '2026-10-19', 'exit', 100), leaveTarget(1, '2026-10-19', 'early', 100),
            leaveTarget(1, '2026-10-19', 'late', 100)],
    );
    expect(array_column($result['allocations'], 'target_kind'))->toBe(['late', 'early', 'exit'])
        ->and($result['remaining']['1|exit'])->toBe(60);
});
