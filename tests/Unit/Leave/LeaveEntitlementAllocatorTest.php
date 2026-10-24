<?php

use App\Services\Leave\LeaveEntitlementAllocator;

function entitlementSource(int $id, string $date, float $quantity): array
{
    return ['id' => $id, 'work_date' => $date, 'quantity' => $quantity];
}

function compensationRequest(int $id, string $date, float $quantity): array
{
    return [
        'request_id' => $id, 'external_id' => 'FORM-'.$id, 'start_date' => $date,
        'quantity' => $quantity, 'request_day_key' => $id.'|'.$date,
    ];
}

test('a full entitlement splits between two half-day requests in request order', function (): void {
    $plan = (new LeaveEntitlementAllocator)->allocate([
        compensationRequest(2, '2026-10-20', 0.5), compensationRequest(1, '2026-10-19', 0.5),
    ], [entitlementSource(10, '2026-10-16', 1.0)]);

    expect(array_column($plan['consumptions'], 'request_id'))->toBe([1, 2])
        ->and(array_column($plan['consumptions'], 'quantity'))->toBe([0.5, 0.5])
        ->and($plan['exceeded'])->toBe([]);
});

test('two same-day requests use id order and the second cannot partly consume', function (): void {
    $plan = (new LeaveEntitlementAllocator)->allocate([
        compensationRequest(8, '2026-10-19', 1.0), compensationRequest(7, '2026-10-19', 1.0),
    ], [entitlementSource(11, '2026-10-17', 0.5), entitlementSource(10, '2026-10-16', 0.5)]);

    expect(array_column($plan['consumptions'], 'request_id'))->toBe([7, 7])
        ->and(array_column($plan['consumptions'], 'entitlement_id'))->toBe([10, 11])
        ->and($plan['exceeded'])->toBe([8]);
});

test('a request cannot spend an entitlement earned after its start', function (): void {
    $plan = (new LeaveEntitlementAllocator)->allocate(
        [compensationRequest(1, '2026-10-19', 0.5)], [entitlementSource(10, '2026-10-20', 1.0)],
    );

    expect($plan['consumptions'])->toBe([])->and($plan['exceeded'])->toBe([1]);
});

test('removing a cancelled request restores the oldest entitlement for the next request', function (): void {
    $allocator = new LeaveEntitlementAllocator;
    $requests = [compensationRequest(1, '2026-10-19', 1.0), compensationRequest(2, '2026-10-20', 1.0)];
    $entitlements = [entitlementSource(10, '2026-10-16', 1.0)];

    expect($allocator->allocate($requests, $entitlements)['exceeded'])->toBe([2])
        ->and($allocator->allocate([$requests[1]], $entitlements)['consumptions'][0]['request_id'])->toBe(2);
});
