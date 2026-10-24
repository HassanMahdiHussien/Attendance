<?php

use App\Services\Attendance\ShiftPunchOwnership;

test('a midnight checkout closes the evening shift once', function (): void {
    $owned = (new ShiftPunchOwnership)->assign([
        ['id' => 3, 'punched_at' => '2026-10-20 00:00:00', 'role' => 'check_out'],
        ['id' => 1, 'punched_at' => '2026-10-19 16:00:00', 'role' => 'check_in'],
        ['id' => 4, 'punched_at' => '2026-10-20 08:00:00', 'role' => 'check_out'],
        ['id' => 2, 'punched_at' => '2026-10-20 00:00:00', 'role' => 'check_in'],
    ]);

    expect(array_column($owned['2026-10-19'], 'id'))->toBe([1, 3])
        ->and(array_column($owned['2026-10-20'], 'id'))->toBe([2, 4])
        ->and(array_sum(array_map('count', $owned)))->toBe(4);
});

test('a near-midnight check-in belongs to the nearest midnight and manual reversals retain ownership', function (): void {
    $owned = (new ShiftPunchOwnership)->assign([
        ['id' => 1, 'punched_at' => '2026-10-19 22:00:00', 'role' => 'check_in', 'manual' => true],
        ['id' => 2, 'punched_at' => '2026-10-19 22:00:00', 'role' => 'check_in', 'manual' => true, 'reverses_id' => 1],
        ['id' => 3, 'punched_at' => '2026-10-20 08:00:00', 'role' => 'check_out'],
    ]);

    expect(array_column($owned['2026-10-20'], 'id'))->toBe([1, 2, 3])
        ->and($owned['2026-10-19'] ?? [])->toBe([]);
});
