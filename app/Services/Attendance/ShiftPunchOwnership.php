<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

class ShiftPunchOwnership
{
    public function assign(array $punches): array
    {
        usort($punches, fn (array $a, array $b): int => [$a['punched_at'], (int) ($a['manual'] ?? false), (int) ($a['id'] ?? 0)]
            <=> [$b['punched_at'], (int) ($b['manual'] ?? false), (int) ($b['id'] ?? 0)]);
        $owned = [];
        $open = [];
        $manualDates = [];
        foreach ($punches as $punch) {
            if (($punch['reverses_id'] ?? null) !== null) {
                $date = $manualDates[(int) $punch['reverses_id']] ?? substr($punch['punched_at'], 0, 10);
                $owned[$date][] = $punch;

                continue;
            }
            $at = CarbonImmutable::parse($punch['punched_at'], 'Asia/Baghdad');
            $date = $at->toDateString();
            $clock = $at->format('H:i:s');
            if ($punch['role'] === 'check_in') {
                if ($clock >= '22:00:00') {
                    $date = $at->addDay()->toDateString();
                }
                $open[$date] ??= [];
                $open[$date][] = $at;
            } elseif ($punch['role'] === 'check_out' && $clock <= '02:00:00') {
                $previous = $at->subDay()->toDateString();
                if ($this->hasEveningStart($open[$previous] ?? [], $previous)) {
                    $date = $previous;
                }
            }
            if ($punch['role'] === 'check_out' && ($open[$date] ?? []) !== []) {
                array_shift($open[$date]);
            }
            if ($punch['manual'] ?? false) {
                $manualDates[(int) $punch['id']] = $date;
            }
            $owned[$date][] = $punch;
        }

        return $owned;
    }

    private function hasEveningStart(array $open, string $date): bool
    {
        foreach ($open as $at) {
            if ($at >= CarbonImmutable::parse($date.' 14:00:00', 'Asia/Baghdad')) {
                return true;
            }
        }

        return false;
    }
}
