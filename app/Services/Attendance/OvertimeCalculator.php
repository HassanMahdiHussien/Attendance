<?php

namespace App\Services\Attendance;

use DateTimeImmutable;
use DateTimeZone;

class OvertimeCalculator
{
    public function calculate(array $day, array $policy, array $employee, array $holidayIds): array
    {
        $empty = ['overtime_minutes' => null, 'early_arrival_minutes' => null, 'overtime_basis' => null, 'entitlement' => null];
        $status = $day['status'] ?? null;
        if (! in_array($status, ['present', 'rest_day_worked', 'holiday_worked'], true)
            || ! ($day['intervals_complete'] ?? false) || ! ($day['assessment_closed'] ?? false)
            || ($day['intervals'] ?? []) === []) {
            return $empty;
        }

        $zone = new DateTimeZone('Asia/Baghdad');
        $date = substr((string) $day['work_date'], 0, 10);
        $pattern = $employee['work_pattern'] ?? null;
        $firstIn = isset($day['first_in']) ? new DateTimeImmutable($day['first_in'], $zone) : null;
        $startClock = $pattern === 'office'
            ? ($employee['schedule_start'] ?? $policy['office_start'] ?? '08:00:00')
            : ($day['shift_start'] ?? null);
        $start = $startClock === null ? null : new DateTimeImmutable($date.' '.$startClock, $zone);
        $early = $firstIn !== null && $start !== null
            ? max(0, intdiv($start->getTimestamp() - $firstIn->getTimestamp(), 60)) : null;
        $step = max(1, (int) ($policy['overtime_step_minutes'] ?? 15));
        $restOrHoliday = in_array($status, ['rest_day_worked', 'holiday_worked'], true);
        $basis = $restOrHoliday ? ($holidayIds !== [] ? 'holiday' : 'rest_day') : 'after_end';
        $minutes = 0;
        $workedMinutes = (int) ($day['presence_minutes'] ?? 0);
        if ($restOrHoliday) {
            $minutes = $workedMinutes;
        } elseif ($day['required_leave_at'] !== null) {
            $end = new DateTimeImmutable($day['required_leave_at'], $zone);
            foreach ($day['intervals'] as $interval) {
                $from = new DateTimeImmutable($interval['from'], $zone);
                $to = new DateTimeImmutable($interval['to'], $zone);
                $minutes += max(0, intdiv($to->getTimestamp() - max($from->getTimestamp(), $end->getTimestamp()), 60));
            }
            if ($minutes < (int) ($policy['overtime_min_minutes'] ?? 30)) {
                $minutes = 0;
            }
        }
        $minutes = intdiv($minutes, $step) * $step;

        $entitlement = null;
        $weekday = (int) (new DateTimeImmutable($date, $zone))->format('N');
        $restDays = $employee['rest_days'] ?? ($pattern === 'office'
            ? ($policy['office_rest_days'] ?? [5, 6]) : ($policy['shift_rest_days'] ?? [5]));
        $compensatory = $policy['compensatory_rest_days'] ?? ['office' => [5], 'shift' => [5], 'security' => 'individual'];
        $eligibleDays = $pattern === 'security' ? $restDays : ($compensatory[$pattern] ?? []);
        if ($restOrHoliday && is_array($restDays) && in_array($weekday, $restDays, true)
            && is_array($eligibleDays) && in_array($weekday, $eligibleDays, true)) {
            $half = (int) ($policy['rest_day_half_minutes'] ?? 240);
            $full = (int) ($policy['rest_day_full_minutes'] ?? 480);
            $quantity = $workedMinutes >= $full ? 1.0 : ($workedMinutes >= $half ? 0.5 : null);
            if ($quantity !== null) {
                $entitlement = [
                    'quantity' => $quantity, 'worked_minutes' => $workedMinutes,
                    'basis' => match ($pattern) {
                        'office' => 'office_friday',
                        'shift' => 'shift_rest_day',
                        default => 'security_rest_day',
                    },
                    'holiday' => $holidayIds !== [],
                ];
            }
        }

        return [
            'overtime_minutes' => $minutes, 'early_arrival_minutes' => $early,
            'overtime_basis' => $basis, 'entitlement' => $entitlement,
        ];
    }
}
