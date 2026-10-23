<?php

namespace App\Services\Leave;

use DateTimeImmutable;
use DateTimeZone;

class LeaveAllocator
{
    public const KINDS = ['absence', 'late', 'early', 'exit'];

    public function allocate(array $sources, array $targets, array $blocked = [], ?int $expiryDays = null, array $targetlessPresentDates = [], ?array $creditEligibleDates = null): array
    {
        usort($sources, fn (array $a, array $b): int => [$a['work_date'], $a['external_id'], $a['id']] <=> [$b['work_date'], $b['external_id'], $b['id']]);
        usort($targets, fn (array $a, array $b): int => [$a['work_date'], array_search($a['kind'], self::KINDS, true), $a['day_id']]
            <=> [$b['work_date'], array_search($b['kind'], self::KINDS, true), $b['day_id']]);
        $remaining = [];
        foreach ($targets as $target) {
            $remaining[$target['day_id'].'|'.$target['kind']] = (int) $target['minutes'];
        }
        $allocations = [];
        $credits = [];
        $flags = [];
        foreach ($sources as $source) {
            $available = (int) $source['minutes'];
            $sameDay = array_filter($targets, fn (array $target): bool => $target['work_date'] === $source['work_date']);
            if (in_array($source['work_date'], $targetlessPresentDates, true)) {
                $flags[] = ['request_day_id' => $source['id'], 'code' => 'leave_with_attendance_nothing_to_reconcile'];
            }
            foreach ($sameDay as $target) {
                $key = $target['day_id'].'|'.$target['kind'];
                if (isset($blocked[$target['work_date'].'|'.$target['kind']])) {
                    continue;
                }
                $used = min($available, $remaining[$key]);
                if ($used > 0) {
                    $allocations[] = $this->allocation($source, $target, $used, 'same_day');
                    $remaining[$key] -= $used;
                    $available -= $used;
                }
            }
            if ($available > 0 && ($creditEligibleDates === null || in_array($source['work_date'], $creditEligibleDates, true))) {
                $credits[] = [
                    'source_day_id' => $source['id'], 'work_date' => $source['work_date'],
                    'external_id' => $source['external_id'], 'minutes' => $available,
                    'remaining' => $available,
                    'expires_on' => $expiryDays === null ? null : (new DateTimeImmutable($source['work_date'], new DateTimeZone('UTC')))
                        ->modify('+'.$expiryDays.' days')->format('Y-m-d'),
                ];
            }
        }
        foreach ($targets as $target) {
            $key = $target['day_id'].'|'.$target['kind'];
            if (isset($blocked[$target['work_date'].'|'.$target['kind']])) {
                continue;
            }
            foreach ($credits as &$credit) {
                if ($remaining[$key] === 0) {
                    break;
                }
                if ($credit['remaining'] === 0 || ($credit['expires_on'] !== null && $target['work_date'] > $credit['expires_on'])) {
                    continue;
                }
                $used = min($credit['remaining'], $remaining[$key]);
                $allocations[] = $this->allocation([
                    'id' => $credit['source_day_id'], 'external_id' => $credit['external_id'],
                    'work_date' => $credit['work_date'],
                ], $target, $used, 'credit');
                $credit['remaining'] -= $used;
                $remaining[$key] -= $used;
            }
            unset($credit);
        }

        return ['allocations' => $allocations, 'credits' => $credits, 'remaining' => $remaining, 'flags' => $flags];
    }

    private function allocation(array $source, array $target, int $minutes, string $kind): array
    {
        return [
            'source_day_id' => $source['id'], 'target_day_id' => $target['day_id'],
            'target_kind' => $target['kind'], 'minutes' => $minutes, 'source_kind' => $kind,
            'explanation' => [
                'code' => $kind === 'credit' ? 'leave_credit_applied' : 'leave_same_day',
                'request' => $source['external_id'], 'source_date' => $source['work_date'],
                'target_date' => $target['work_date'], 'target_kind' => $target['kind'], 'minutes' => $minutes,
            ],
        ];
    }
}
