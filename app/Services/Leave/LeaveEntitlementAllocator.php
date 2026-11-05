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
        $missingWorkedDate = [];
        // A confirmed form holds only the credit of the dates it names; for anything else it keeps its normal
        // place in the order, so a form whose named date has no credit can't displace another form.
        $held = [];
        foreach ($requests as $index => $request) {
            $preferredDates = $this->preferredDates($request);
            if ($preferredDates === []) {
                continue;
            }
            $needed = (int) round((float) $request['quantity'] * 2);
            $named = array_filter($entitlements, fn (array $entitlement): bool => in_array($entitlement['work_date'], $preferredDates, true));
            usort($named, fn (array $a, array $b): int => [array_search($a['work_date'], $preferredDates, true), $a['id']]
                <=> [array_search($b['work_date'], $preferredDates, true), $b['id']]);
            foreach ($named as $entitlement) {
                $used = min($needed, $available[$entitlement['id']]);
                if ($used > 0) {
                    $available[$entitlement['id']] -= $used;
                    $needed -= $used;
                    $held[$index][] = [$entitlement, $used];
                }
            }
            if ($needed > 0) {
                $missingWorkedDate[] = (int) $request['request_id'];
            }
        }
        $consumptions = [];
        $exceeded = [];
        foreach ($requests as $index => $request) {
            $needed = (int) round((float) $request['quantity'] * 2);
            $parts = $held[$index] ?? [];
            $remaining = $needed - array_sum(array_column($parts, 1));
            $eligible = array_filter($entitlements, fn (array $entitlement): bool => $entitlement['work_date'] <= $request['start_date']);
            $availableAtStart = array_sum(array_map(fn (array $entitlement): int => $available[$entitlement['id']], $eligible));
            if ($needed <= 0 || $availableAtStart < $remaining) {
                foreach ($parts as [$entitlement, $used]) {
                    $available[$entitlement['id']] += $used;
                }
                $exceeded[] = (int) $request['request_id'];

                continue;
            }
            foreach ($eligible as $entitlement) {
                if ($remaining === 0) {
                    break;
                }
                $used = min($remaining, $available[$entitlement['id']]);
                if ($used > 0) {
                    $available[$entitlement['id']] -= $used;
                    $remaining -= $used;
                    $parts[] = [$entitlement, $used];
                }
            }
            foreach ($parts as [$entitlement, $used]) {
                $consumptions[] = [
                    'request_id' => (int) $request['request_id'],
                    'request_day_key' => $request['request_day_key'],
                    'entitlement_id' => (int) $entitlement['id'],
                    'quantity' => $used / 2,
                    'explanation' => [
                        'code' => 'rest_day_entitlement_used',
                        'request' => $request['external_id'],
                        'entitlement_date' => $entitlement['work_date'],
                        'quantity' => $used / 2,
                    ],
                ];
            }
        }

        return ['consumptions' => $consumptions, 'exceeded' => $exceeded, 'missing_worked_date' => $missingWorkedDate];
    }

    private function preferredDates(array $request): array
    {
        return array_values($request['worked_dates'] ?? array_filter([$request['worked_date'] ?? null]));
    }
}
