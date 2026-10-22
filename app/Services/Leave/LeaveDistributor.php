<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;

class LeaveDistributor
{
    public function distribute(array $request, array $versions, array $policies, ?array $confirmation = null): array
    {
        $start = $request['start_date'] ?? null;
        $end = $request['end_date'] ?? null;
        $duration = $request['duration_days'] ?? null;
        if ($start === null || $end === null || $start < '2000-01-01' || $start > $end || $duration === null
            || (float) $duration <= 0 || (float) $duration * 2 !== (float) (int) ((float) $duration * 2)) {
            return ['days' => [], 'flag' => 'invalid_dates'];
        }
        $startDay = CarbonImmutable::parse($start);
        $endDay = CarbonImmutable::parse($end);
        if ($startDay->diffInDays($endDay) > 3660) {
            return ['days' => [], 'flag' => 'invalid_dates'];
        }
        usort($versions, fn (array $a, array $b): int => [$a['effective_from'], $a['id']] <=> [$b['effective_from'], $b['id']]);
        usort($policies, fn (array $a, array $b): int => [$a['effective_from'], $a['version']] <=> [$b['effective_from'], $b['version']]);
        $values = [];
        $versionIndex = 0;
        $policyIndex = 0;
        $restDays = [5, 6];
        $working = [];
        for ($date = $startDay; $date <= $endDay; $date = $date->addDay()) {
            $day = $date->toDateString();
            while (isset($versions[$versionIndex]) && $versions[$versionIndex]['effective_from'] <= $day) {
                foreach ($versions[$versionIndex]['changes'] as $field => $value) {
                    $values[$field] = $value;
                }
                $versionIndex++;
            }
            while (isset($policies[$policyIndex]) && $policies[$policyIndex]['effective_from'] <= $day) {
                $restDays = $policies[$policyIndex]['office_rest_days'];
                $policyIndex++;
            }
            if (! in_array((int) $date->format('N'), $values['rest_days'] ?? $restDays, true)) {
                $working[] = $day;
            }
        }
        $whole = (int) floor((float) $duration);
        $half = ((float) $duration - $whole) > 0;
        $needed = $whole + (int) $half;
        $dates = $working;
        if ($confirmation !== null) {
            if (($confirmation['mode'] ?? null) === 'from_start') {
                $dates = array_slice($working, 0, $needed);
            } elseif (($confirmation['mode'] ?? null) === 'dates') {
                $dates = array_values(array_unique($confirmation['dates'] ?? []));
                sort($dates);
                if (array_diff($dates, $working) !== []) {
                    return ['days' => [], 'flag' => 'duration_mismatch'];
                }
            }
        }
        if (count($dates) !== $needed) {
            return ['days' => [], 'flag' => 'duration_mismatch'];
        }
        $days = [];
        foreach ($dates as $index => $date) {
            $days[] = ['work_date' => $date, 'minutes' => $index < $whole ? 480 : 240];
        }

        return ['days' => $days, 'flag' => null];
    }
}
