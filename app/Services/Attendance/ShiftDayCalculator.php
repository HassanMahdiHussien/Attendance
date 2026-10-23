<?php

namespace App\Services\Attendance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class ShiftDayCalculator
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
        int $approvedLeaveMinutes = 0,
    ): array {
        $zone = new DateTimeZone('Asia/Baghdad');
        $day = new DateTimeImmutable($date.' 00:00:00', $zone);
        $current = $now instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($now)->setTimezone($zone)
            : new DateTimeImmutable($now, $zone);
        $pattern = $employeeValues['work_pattern'] ?? null;
        $values = [
            'work_pattern' => $pattern, 'hire_date' => $employeeValues['hire_date'] ?? null,
            'last_working_day' => $employeeValues['last_working_day'] ?? null,
            'exit_status' => $employeeValues['exit_status'] ?? null,
            'branch_id' => $employeeValues['branch_id'] ?? null,
            'rest_days' => $employeeValues['rest_days'] ?? null,
            'absence_minutes' => (int) ($policy['absence_minutes'] ?? 480),
            'policy_version' => $policy['version'] ?? null,
        ];
        $result = [
            'work_date' => $date, 'branch_id' => $values['branch_id'], 'status' => 'pending',
            'first_in' => null, 'last_out' => null, 'elapsed_minutes' => null,
            'presence_minutes' => null, 'late_minutes' => null, 'early_minutes' => null,
            'exit_minutes' => null, 'short_minutes' => null, 'balance_minutes' => null,
            'required_leave_at' => null, 'intervals' => [], 'exits' => [],
            'policy_id' => $policy['id'] ?? null, 'inputs' => $values, 'reviewed' => false,
            'shift_start' => null, 'shift_source' => 'unknown',
            'shift_confidence' => 'unresolved', 'shift_evidence' => [],
        ];

        if (($values['hire_date'] !== null && substr((string) $values['hire_date'], 0, 10) > $date)
            || ($values['exit_status'] === 'confirmed' && $values['last_working_day'] !== null
                && substr((string) $values['last_working_day'], 0, 10) < $date)) {
            $result['status'] = 'not_employed';

            return $result;
        }
        if (! in_array($pattern, ['shift', 'security'], true)) {
            $result['status'] = 'pattern_missing';
            $result['inputs']['reason'] = 'missing_pattern';

            return $result;
        }
        if ($pattern === 'security' && $values['rest_days'] === null) {
            $result['status'] = 'pattern_missing';
            $result['inputs']['reason'] = 'rest_day_missing';

            return $result;
        }
        $restDays = $values['rest_days'] ?? $policy['shift_rest_days'] ?? [5];
        if (in_array((int) $day->format('N'), $restDays, true)) {
            $result['status'] = 'rest_day';

            return $result;
        }

        $timeline = $this->timeline($punches, $manualPunches, $zone);
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
        if ($firstIn !== null && $lastOut !== null && $lastOut >= $firstIn) {
            $result['elapsed_minutes'] = $this->minutes($firstIn, $lastOut);
        }
        $presence = 0;
        $previousEnd = null;
        foreach ($intervals as [$in, $out]) {
            $presence += $this->minutes($in, $out);
            $result['intervals'][] = ['from' => $in->format('Y-m-d H:i:s'), 'to' => $out->format('Y-m-d H:i:s')];
            if ($previousEnd !== null && $in > $previousEnd) {
                $result['exits'][] = [
                    'from' => $previousEnd->format('Y-m-d H:i:s'), 'to' => $in->format('Y-m-d H:i:s'),
                    'minutes' => $this->minutes($previousEnd, $in),
                ];
            }
            $previousEnd = $out;
        }
        $result['presence_minutes'] = $intervals === [] ? null : $presence;
        $result['exit_minutes'] = array_sum(array_column($result['exits'], 'minutes'));

        usort($decisions, fn (array $a, array $b): int => [($a['created_at'] ?? ''), (int) ($a['id'] ?? 0)]
            <=> [($b['created_at'] ?? ''), (int) ($b['id'] ?? 0)]);
        $choice = null;
        $confirmedAbsence = false;
        foreach ($decisions as $decision) {
            if (($decision['decision'] ?? null) === 'pick_shift') {
                $choice = $decision['shift_start'] ?? null;
            } elseif (($decision['decision'] ?? null) === 'withdraw_shift') {
                $choice = null;
            } elseif (($decision['decision'] ?? null) === 'confirm_absence') {
                $confirmedAbsence = true;
            } elseif (($decision['decision'] ?? null) === 'withdraw_confirmation') {
                $confirmedAbsence = false;
            }
        }
        $result['reviewed'] = $confirmedAbsence || $choice !== null;
        $shiftMinutes = (int) ($policy['shift_minutes'] ?? 480);
        $inference = $choice !== null && $timeline !== []
            ? ['start' => $this->shiftStart($day, $choice), 'source' => 'hr', 'confidence' => 'clear']
            : $this->infer($day, $policy, $firstIn, $lastOut, $approvedLeaveMinutes);
        $start = $inference['start'];
        $result['shift_source'] = $inference['source'];
        $result['shift_confidence'] = $inference['confidence'];
        $result['shift_start'] = $start?->format('H:i:s');
        $result['shift_evidence'] = [
            'first_in' => $result['first_in'], 'last_out' => $result['last_out'],
            'approved_leave_minutes' => $approvedLeaveMinutes,
            'reason' => $inference['confidence'],
        ];
        $end = $start?->modify('+'.$shiftMinutes.' minutes');
        $requiredEnd = $end !== null && $timeline !== [] && ! $confirmedAbsence
            ? $end : $day->modify('+1 day');
        $result['required_leave_at'] = $requiredEnd->format('Y-m-d H:i:s');

        if ($start !== null) {
            if ($firstIn !== null) {
                $result['late_minutes'] = max(0, $this->minutes($start, $firstIn));
            }
            if ($lastOut !== null && ! $unmatched && $firstIn !== null) {
                $result['early_minutes'] = max(0, $this->minutes($lastOut, $end));
            }
        }
        $close = $requiredEnd->modify('+'.(int) ($policy['close_grace_minutes'] ?? 120).' minutes');
        if (! $coverage || $current < $close) {
            return $result;
        }
        if ($confirmedAbsence) {
            $result['status'] = 'absent';
        } elseif ($outage) {
            $result['status'] = 'device_outage';
        } elseif ($timeline === []) {
            $result['status'] = 'absent';
        } elseif ($start === null) {
            $result['status'] = $firstIn === null || $lastOut === null ? 'missing_punch' : 'shift_unresolved';
        } elseif ($unmatched || $firstIn === null || $lastOut === null || $intervals === []) {
            $result['status'] = 'missing_punch';
        } else {
            $result['status'] = 'present';
            $minimum = (int) ($policy[$pattern === 'security' ? 'security_min_presence_minutes' : 'shift_min_presence_minutes'] ?? 480);
            $result['short_minutes'] = max(0, $minimum - $presence);
            $result['balance_minutes'] = (int) $result['late_minutes'] + (int) $result['early_minutes']
                + (! empty($policy['exits_count_toward_balance']) ? $result['exit_minutes'] : 0);
        }

        return $result;
    }

    private function infer(DateTimeImmutable $day, array $policy, ?DateTimeImmutable $firstIn, ?DateTimeImmutable $lastOut, int $leaveMinutes): array
    {
        if ($firstIn === null && $lastOut === null) {
            return ['start' => null, 'source' => 'unknown', 'confidence' => 'unresolved'];
        }
        $tolerance = (int) ($policy['inference_tolerance_minutes'] ?? 120);
        $length = (int) ($policy['shift_minutes'] ?? 480);
        $clear = [];
        $explained = [];
        foreach ($policy['shift_starts'] ?? ['00:00', '08:00', '16:00'] as $clock) {
            $start = $this->shiftStart($day, $clock);
            $end = $start->modify('+'.$length.' minutes');
            $startGap = $firstIn === null ? null : abs($this->minutes($start, $firstIn));
            $endGap = $lastOut === null ? null : abs($this->minutes($end, $lastOut));
            if (($startGap === null || $startGap <= $tolerance) && ($endGap === null || $endGap <= $tolerance)) {
                $clear[] = ['start' => $start, 'score' => ($startGap ?? 0) + ($endGap ?? 0)];
            }
            if ($leaveMinutes === 240 && $startGap !== null && $endGap !== null) {
                $lateStart = $this->minutes($start, $firstIn);
                $earlyEnd = $this->minutes($lastOut, $end);
                if (($lateStart > $tolerance && abs($lateStart - 240) <= $tolerance && $endGap <= $tolerance)
                    || ($earlyEnd > $tolerance && abs($earlyEnd - 240) <= $tolerance && $startGap <= $tolerance)) {
                    $explained[] = ['start' => $start, 'score' => abs($lateStart - 240) + $endGap];
                }
            }
        }
        if ($clear !== []) {
            usort($clear, fn (array $a, array $b): int => $a['score'] <=> $b['score']);
            if (! isset($clear[1]) || $clear[0]['score'] < $clear[1]['score']) {
                return ['start' => $clear[0]['start'], 'source' => 'inferred', 'confidence' => 'clear'];
            }
        }
        if (count($explained) === 1) {
            return ['start' => $explained[0]['start'], 'source' => 'inferred', 'confidence' => 'leave_explained'];
        }

        return ['start' => null, 'source' => 'unknown', 'confidence' => 'unresolved'];
    }

    private function shiftStart(DateTimeImmutable $day, string $clock): DateTimeImmutable
    {
        return new DateTimeImmutable($day->format('Y-m-d').' '.$clock, $day->getTimezone());
    }

    private function timeline(array $punches, array $manualPunches, DateTimeZone $zone): array
    {
        $rows = [];
        $reversed = [];
        foreach ($manualPunches as $punch) {
            if (($punch['reverses_id'] ?? null) !== null) {
                $reversed[(int) $punch['reverses_id']] = true;
            }
        }
        foreach ($punches as $punch) {
            if (($punch['status'] ?? 'used') === 'used') {
                $rows[] = $this->timelineRow($punch, false, $zone);
            }
        }
        foreach ($manualPunches as $punch) {
            if (($punch['reverses_id'] ?? null) === null && ! isset($reversed[(int) ($punch['id'] ?? 0)])) {
                $rows[] = $this->timelineRow($punch, true, $zone);
            }
        }
        usort($rows, fn (array $a, array $b): int => ($a['time'] <=> $b['time'])
            ?: ($a['manual'] <=> $b['manual']) ?: ($a['id'] <=> $b['id']));

        return $rows;
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
