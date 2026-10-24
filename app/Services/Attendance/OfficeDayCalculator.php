<?php

namespace App\Services\Attendance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class OfficeDayCalculator
{
    public function calculate(
        array $employeeValues,
        array $policy,
        array $punches,
        array $manualPunches,
        array $decisions,
        bool $coverage,
        bool $outage,
        string $date,
        DateTimeInterface|string $now,
    ): array {
        $zone = new DateTimeZone('Asia/Baghdad');
        $day = new DateTimeImmutable($date.' 00:00:00', $zone);
        $current = $now instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($now)->setTimezone($zone)
            : new DateTimeImmutable($now, $zone);
        $values = [
            'work_pattern' => $employeeValues['work_pattern'] ?? null,
            'hire_date' => $employeeValues['hire_date'] ?? null,
            'last_working_day' => $employeeValues['last_working_day'] ?? null,
            'exit_status' => $employeeValues['exit_status'] ?? null,
            'branch_id' => $employeeValues['branch_id'] ?? null,
            'rest_days' => $employeeValues['rest_days'] ?? null,
            'schedule_start' => $employeeValues['schedule_start'] ?? null,
            'schedule_end' => $employeeValues['schedule_end'] ?? null,
        ];
        $result = [
            'work_date' => $date, 'branch_id' => $values['branch_id'], 'status' => 'pending',
            'first_in' => null, 'last_out' => null, 'elapsed_minutes' => null,
            'presence_minutes' => null, 'late_minutes' => null, 'early_minutes' => null,
            'exit_minutes' => null, 'short_minutes' => null, 'balance_minutes' => null,
            'required_leave_at' => null, 'intervals' => [], 'exits' => [],
            'policy_id' => $policy['id'] ?? null, 'inputs' => $values + ['policy_version' => $policy['version'] ?? null],
            'reviewed' => false,
        ];

        if (($values['hire_date'] !== null && substr((string) $values['hire_date'], 0, 10) > $date)
            || ($values['exit_status'] === 'confirmed' && $values['last_working_day'] !== null
                && substr((string) $values['last_working_day'], 0, 10) < $date)) {
            $result['status'] = 'not_employed';

            return $result;
        }
        if ($values['work_pattern'] !== 'office') {
            $result['status'] = 'pattern_missing';
            $result['inputs']['reason'] = $values['work_pattern'] === null ? 'missing_pattern' : 'other_pattern';

            return $result;
        }
        $restDays = $values['rest_days'] ?? $policy['office_rest_days'] ?? [5, 6];
        $restDay = in_array((int) $day->format('N'), $restDays, true);
        if ($restDay && $punches === [] && $manualPunches === []) {
            $result['status'] = 'rest_day';

            return $result;
        }

        $workday = (int) ($policy['workday_minutes'] ?? 540);
        $startOverride = $values['schedule_start'];
        $endOverride = $values['schedule_end'];
        $start = $startOverride !== null
            ? $this->clock($day, (string) $startOverride)
            : $this->clock($day, (string) ($policy['office_start'] ?? '08:00:00'));
        if ($endOverride !== null) {
            $end = $this->clock($day, (string) $endOverride);
            if ($startOverride !== null) {
                $workday = $this->minutes($start, $end);
            } else {
                $start = $end->modify('-'.$workday.' minutes');
            }
        }
        if ($workday <= 0) {
            $result['status'] = 'pattern_missing';
            $result['inputs']['reason'] = 'invalid_schedule';

            return $result;
        }

        $flexEnd = $start->modify('+'.(int) ($policy['flex_minutes'] ?? 60).' minutes');
        $latestEnd = $flexEnd->modify('+'.$workday.' minutes');
        $timeline = [];
        $reversedIds = [];
        foreach ($manualPunches as $punch) {
            if (($punch['reverses_id'] ?? null) !== null) {
                $reversedIds[(int) $punch['reverses_id']] = true;
            }
        }
        foreach ($punches as $punch) {
            if (($punch['status'] ?? 'used') === 'used') {
                $timeline[] = $this->timelineRow($punch, false, $zone);
            }
        }
        foreach ($manualPunches as $punch) {
            if (($punch['reverses_id'] ?? null) === null && ! isset($reversedIds[(int) ($punch['id'] ?? 0)])) {
                $timeline[] = $this->timelineRow($punch, true, $zone);
            }
        }
        usort($timeline, fn (array $a, array $b): int => ($a['time'] <=> $b['time'])
            ?: ($a['manual'] <=> $b['manual']) ?: ($a['id'] <=> $b['id']));

        $firstIn = null;
        $lastOut = null;
        $open = null;
        $unmatched = false;
        $intervals = [];
        foreach ($timeline as $punch) {
            if ($punch['role'] === 'check_in') {
                $firstIn ??= $punch['time'];
                if ($open !== null) {
                    $unmatched = true;
                } else {
                    $open = $punch['time'];
                }
            } elseif ($punch['role'] === 'check_out') {
                $lastOut = $punch['time'];
                if ($open === null || $punch['time'] < $open) {
                    $unmatched = true;
                } else {
                    $intervals[] = [$open, $punch['time']];
                    $open = null;
                }
            }
        }
        $unmatched = $unmatched || $open !== null;
        $result['first_in'] = $firstIn?->format('Y-m-d H:i:s');
        $result['last_out'] = $lastOut?->format('Y-m-d H:i:s');
        $requiredEnd = $latestEnd;
        if ($firstIn !== null) {
            $result['late_minutes'] = 0;
            if ($firstIn <= $start) {
                $requiredEnd = $start->modify('+'.$workday.' minutes');
            } elseif ($firstIn <= $flexEnd) {
                $requiredEnd = $firstIn->modify('+'.$workday.' minutes');
            } else {
                $result['late_minutes'] = $this->minutes($flexEnd, $firstIn);
            }
        }
        $result['required_leave_at'] = $requiredEnd->format('Y-m-d H:i:s');
        if ($firstIn !== null && $lastOut !== null && $lastOut >= $firstIn) {
            $result['elapsed_minutes'] = $this->minutes($firstIn, $lastOut);
        }
        $presence = 0;
        $exits = [];
        $previousEnd = null;
        foreach ($intervals as [$in, $out]) {
            $presence += $this->minutes($in, $out);
            if ($previousEnd !== null && $in > $previousEnd) {
                $exits[] = ['from' => $previousEnd->format('Y-m-d H:i:s'), 'to' => $in->format('Y-m-d H:i:s'), 'minutes' => $this->minutes($previousEnd, $in)];
            }
            $result['intervals'][] = ['from' => $in->format('Y-m-d H:i:s'), 'to' => $out->format('Y-m-d H:i:s')];
            $previousEnd = $out;
        }
        $result['exits'] = $exits;
        if ($intervals !== []) {
            $result['presence_minutes'] = $presence;
        }
        $result['exit_minutes'] = array_sum(array_column($exits, 'minutes'));
        if (! $unmatched && $firstIn !== null && $lastOut !== null) {
            $result['early_minutes'] = max(0, $this->minutes($lastOut, $requiredEnd));
        }

        $latestDecision = null;
        foreach ($decisions as $decision) {
            if ($latestDecision === null || [($decision['created_at'] ?? ''), (int) ($decision['id'] ?? 0)]
                > [($latestDecision['created_at'] ?? ''), (int) ($latestDecision['id'] ?? 0)]) {
                $latestDecision = $decision;
            }
        }
        $confirmedAbsence = ($latestDecision['decision'] ?? null) === 'confirm_absence';
        $result['reviewed'] = $confirmedAbsence;
        if ($restDay) {
            $result['status'] = $timeline === [] ? 'rest_day' : 'rest_day_worked';
            $result['late_minutes'] = 0;
            $result['early_minutes'] = 0;
            $result['short_minutes'] = 0;
            $result['balance_minutes'] = 0;

            return $result;
        }
        if (! $coverage || $current < $requiredEnd->modify('+'.(int) ($policy['close_grace_minutes'] ?? 120).' minutes')) {
            return $result;
        }
        if ($outage) {
            $result['status'] = 'device_outage';
        } elseif ($confirmedAbsence) {
            $result['status'] = 'absent';
        } elseif ($timeline === []) {
            $result['status'] = 'absent';
        } elseif ($unmatched || $firstIn === null || $lastOut === null || $intervals === []) {
            $result['status'] = 'missing_punch';
        } else {
            $result['status'] = 'present';
            $result['short_minutes'] = max(0, (int) ($policy['min_presence_minutes'] ?? 480) - $presence);
            $result['balance_minutes'] = (int) ($result['late_minutes'] ?? 0) + (int) $result['early_minutes']
                + (! empty($policy['exits_count_toward_balance']) ? $result['exit_minutes'] : 0);
        }

        return $result;
    }

    private function clock(DateTimeImmutable $date, string $clock): DateTimeImmutable
    {
        return new DateTimeImmutable($date->format('Y-m-d').' '.$clock, $date->getTimezone());
    }

    private function timelineRow(array $punch, bool $manual, DateTimeZone $zone): array
    {
        return [
            'time' => new DateTimeImmutable((string) $punch['punched_at'], $zone),
            'role' => $punch['role'], 'manual' => $manual, 'id' => (int) ($punch['id'] ?? 0),
        ];
    }

    private function minutes(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
    }
}
