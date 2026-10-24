<?php

namespace App\Services\Leave;

use Carbon\CarbonImmutable;

class LeaveDistributor
{
    public function distribute(array $request, array $versions, array $policies, ?array $confirmation = null, array $leaveCalendarRestDays = [5]): array
    {
        $start = $request['start_date'] ?? null;
        $end = $request['end_date'] ?? null;
        $duration = $request['duration_days'] ?? null;
        if ($start === null || $end === null || $start < '2000-01-01' || $start > $end || $duration === null
            || (float) $duration <= 0 || (float) $duration * 2 !== (float) (int) ((float) $duration * 2)) {
            return ['days' => [], 'flag' => 'invalid_dates', 'counting_method' => null];
        }
        $startDay = CarbonImmutable::parse($start);
        $endDay = CarbonImmutable::parse($end);
        if ($startDay->diffInDays($endDay) > 3660) {
            return ['days' => [], 'flag' => 'invalid_dates', 'counting_method' => null];
        }
        usort($versions, fn (array $a, array $b): int => [$a['effective_from'], $a['id']] <=> [$b['effective_from'], $b['id']]);
        usort($policies, fn (array $a, array $b): int => [$a['effective_from'], $a['version']] <=> [$b['effective_from'], $b['version']]);
        $values = [];
        $versionIndex = 0;
        $policyIndex = 0;
        $officeRestDays = [5, 6];
        $shiftRestDays = [5];
        $working = [];
        $leaveCalendarCount = 0;
        $calendarCount = 0;
        for ($date = $startDay; $date <= $endDay; $date = $date->addDay()) {
            $day = $date->toDateString();
            $weekday = (int) $date->format('N');
            $calendarCount++;
            if (! in_array($weekday, $leaveCalendarRestDays, true)) {
                $leaveCalendarCount++;
            }
            while (isset($versions[$versionIndex]) && $versions[$versionIndex]['effective_from'] <= $day) {
                foreach ($versions[$versionIndex]['changes'] as $field => $value) {
                    $values[$field] = $value;
                }
                $versionIndex++;
            }
            while (isset($policies[$policyIndex]) && $policies[$policyIndex]['effective_from'] <= $day) {
                $officeRestDays = $policies[$policyIndex]['office_rest_days'];
                $shiftRestDays = $policies[$policyIndex]['shift_rest_days'] ?? [5];
                $policyIndex++;
            }
            $pattern = $values['work_pattern'] ?? 'office';
            if ($pattern === 'security' && ($values['rest_days'] ?? null) === null) {
                return ['days' => [], 'flag' => 'rest_day_missing', 'counting_method' => null];
            }
            $restDays = $values['rest_days'] ?? ($pattern === 'shift' ? $shiftRestDays : $officeRestDays);
            if (! in_array($weekday, $restDays, true)) {
                $working[] = $day;
            }
        }
        $whole = (int) floor((float) $duration);
        $half = ((float) $duration - $whole) > 0;
        $needed = $whole + (int) $half;
        $method = null;
        foreach (['employee' => count($working), 'leave_calendar' => $leaveCalendarCount, 'calendar' => $calendarCount] as $candidate => $count) {
            if ($count === $needed) {
                $method = $candidate;

                break;
            }
        }
        $dates = array_slice($working, 0, $needed);
        if ($confirmation !== null) {
            if (($confirmation['mode'] ?? null) === 'from_start') {
                $dates = array_slice($working, 0, $needed);
            } elseif (($confirmation['mode'] ?? null) === 'dates') {
                $dates = array_values(array_unique($confirmation['dates'] ?? []));
                sort($dates);
                if (array_diff($dates, $working) !== []) {
                    return ['days' => [], 'flag' => 'duration_mismatch', 'counting_method' => null];
                }
            }
            $method = 'hr_confirmed';
        } elseif ($method === null) {
            return ['days' => [], 'flag' => 'duration_mismatch', 'counting_method' => null];
        }
        if ($confirmation !== null && count($dates) !== $needed) {
            return ['days' => [], 'flag' => 'duration_mismatch', 'counting_method' => null];
        }
        $days = [];
        foreach ($dates as $index => $date) {
            $days[] = ['work_date' => $date, 'minutes' => $index < $whole ? 480 : 240];
        }

        return ['days' => $days, 'flag' => null, 'counting_method' => $method];
    }
}
