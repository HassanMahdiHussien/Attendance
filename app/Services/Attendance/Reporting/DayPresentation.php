<?php

namespace App\Services\Attendance\Reporting;

use App\Models\Attendance\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;

class DayPresentation
{
    public function __construct(private DurationText $duration) {}

    public function from(array $perDayRow): array
    {
        $status = $perDayRow['status'];
        $minutes = $perDayRow['minutes'];
        $observed = $perDayRow['observed'];
        $late = ($minutes['late']['gross'] ?? 0) > 0;
        $early = ($minutes['early']['gross'] ?? 0) > 0;
        $problems = [];
        $tone = 'gray';
        $needsReview = false;
        $statusKey = $status;

        if ($status === 'absent') {
            $absence = $minutes['absence'];
            $covered = $absence['reconciled_by_leave'] + $absence['reconciled_by_credit'];
            if ($absence['remaining'] > 0) {
                $tone = 'red';
                $needsReview = true;
                $problems[] = $covered > 0
                    ? __('messages.day_presentation.absence_partial', ['covered' => $this->duration->format($covered), 'remaining' => $this->duration->format($absence['remaining'])])
                    : __('messages.day_presentation.absence_full');
            } else {
                $statusKey = 'covered';
                $tone = 'green';
                $problems[] = __('messages.day_presentation.absence_covered');
            }
        } elseif ($status === 'present') {
            foreach (['late', 'early', 'exit'] as $kind) {
                if (($minutes[$kind]['gross'] ?? 0) > 0) {
                    $problems[] = __('messages.day_presentation.'.$kind, ['duration' => $this->duration->format($minutes[$kind]['gross'])]);
                    $needsReview = $needsReview || $minutes[$kind]['remaining'] > 0;
                }
            }
            if (($observed['short'] ?? 0) > 0) {
                $problems[] = __('messages.day_presentation.short_hours', ['duration' => $this->duration->format($observed['short'])]);
                $needsReview = true;
            }
            if ($problems === []) {
                $tone = 'green';
            } elseif ($needsReview) {
                $statusKey = 'short';
                $tone = 'amber';
            } else {
                $statusKey = 'covered';
                $tone = 'green';
                $problems[] = __('messages.day_presentation.covered_note');
            }
        } elseif ($status === 'missing_punch') {
            $tone = 'indigo';
            $needsReview = true;
            $problems[] = __('messages.day_presentation.'.($perDayRow['first_in'] ? 'missing_out' : ($perDayRow['last_out'] ? 'missing_in' : 'missing_punch')));
        } elseif (in_array($status, ['shift_unresolved', 'pattern_missing'], true)) {
            $tone = 'indigo';
            $needsReview = true;
            $problems[] = __('messages.day_presentation.'.$status);
        } elseif ($status === 'pending') {
            $reason = $perDayRow['inputs']['reason'] ?? null;
            $problems[] = match ($reason) {
                'no_device_for_employee' => __('messages.day_presentation.no_device_for_employee'),
                'branch_data_ended' => __('messages.day_presentation.branch_data_ended'),
                default => __('messages.reporting_ui.pending_note'),
            };
        } elseif (in_array($status, ['rest_day_worked', 'holiday_worked', 'field_work'], true)) {
            $tone = 'green';
            if ($status === 'field_work') {
                $name = Holiday::query()->find($perDayRow['field_work_holiday_id'] ?? null)?->name;
                if ($name !== null) {
                    $problems[] = __('messages.announcement.field_day_note', ['name' => $name]);
                }
            }
        }

        $date = CarbonImmutable::parse($perDayRow['work_date'])->locale(app()->getLocale());
        $noPunches = ! $perDayRow['first_in'] && ! $perDayRow['last_out'];
        $worked = $status === 'field_work' ? $this->duration->format(480)
            : ($status === 'missing_punch' ? __('messages.day_presentation.unknown')
            : ($noPunches && in_array($status, ['absent', 'rest_day', 'holiday', 'not_employed', 'pending'], true)
                ? '—' : $this->duration->format((int) ($observed['presence'] ?? 0))));
        $labelKey = $statusKey === 'covered' || $statusKey === 'short' ? 'messages.day_presentation.'.$statusKey : 'messages.office_ui.statuses.'.$statusKey;
        $exits = $status === 'pending' ? [] : ($perDayRow['exits'] ?? []);
        $out = null;
        if ($exits !== []) {
            $total = array_sum(array_column($exits, 'minutes'));
            $rest = $perDayRow['rest_minutes'] ?? null;
            $out = [
                'total' => $this->duration->format($total),
                'over_rest' => $rest !== null && $total > $rest ? $this->duration->format($total - $rest) : null,
                'periods' => array_map(fn (array $exit): array => [
                    'from' => substr($exit['from'], 11, 5), 'to' => substr($exit['to'], 11, 5),
                    'duration' => $this->duration->format((int) $exit['minutes']),
                ], $exits),
            ];
        }

        return [
            'status_key' => $statusKey, 'tone' => $tone,
            'label' => Lang::has($labelKey) ? __($labelKey) : $statusKey,
            'needs_review' => $needsReview, 'late' => $late, 'early' => $early,
            'problems' => $problems, 'worked' => $worked, 'out' => $out,
            'weekday' => $date->translatedFormat('l'), 'date' => $date->translatedFormat('j F'),
        ];
    }
}
