<?php

namespace App\Services\Leave;

class LeaveEntitlementAllocator
{
    public function allocate(array $requests, array $entitlements): array
    {
        usort($requests, fn (array $a, array $b): int => [$a['start_date'], $a['request_id']]
            <=> [$b['start_date'], $b['request_id']]);
        usort($entitlements, fn (array $a, array $b): int => [$a['work_date'], $a['id']]
            <=> [$b['work_date'], $b['id']]);
        $available = [];
        foreach ($entitlements as $entitlement) {
            $available[$entitlement['id']] = (int) round((float) $entitlement['quantity'] * 2);
        }
        $consumptions = [];
        $exceeded = [];
        foreach ($requests as $request) {
            $needed = (int) round((float) $request['quantity'] * 2);
            $eligible = array_filter($entitlements, fn (array $entitlement): bool => $entitlement['work_date'] <= $request['start_date']);
            $availableAtStart = array_sum(array_map(fn (array $entitlement): int => $available[$entitlement['id']], $eligible));
            if ($needed <= 0 || $availableAtStart < $needed) {
                $exceeded[] = (int) $request['request_id'];

                continue;
            }
            foreach ($eligible as $entitlement) {
                $id = $entitlement['id'];
                $used = min($needed, $available[$id]);
                if ($used === 0) {
                    continue;
                }
                $available[$id] -= $used;
                $needed -= $used;
                $consumptions[] = [
                    'request_id' => (int) $request['request_id'],
                    'request_day_key' => $request['request_day_key'],
                    'entitlement_id' => (int) $id,
                    'quantity' => $used / 2,
                    'explanation' => [
                        'code' => 'rest_day_entitlement_used',
                        'request' => $request['external_id'],
                        'entitlement_date' => $entitlement['work_date'],
                        'quantity' => $used / 2,
                    ],
                ];
                if ($needed === 0) {
                    break;
                }
            }
        }

        return ['consumptions' => $consumptions, 'exceeded' => $exceeded];
    }
}
