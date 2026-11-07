<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Day;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ShiftDisplay
{
    public const KEYS = ['office', 'night', 'shift_a', 'shift_b', 'security', 'unresolved'];

    public static function key(?string $pattern, ?string $start): ?string
    {
        if ($pattern === 'office' || $pattern === 'security') {
            return $pattern;
        }
        if ($pattern !== 'shift') {
            return null;
        }
        if ($start === null) {
            return 'unresolved';
        }

        return match (substr($start, 0, 5)) {
            '00:00' => 'night',
            '08:00' => 'shift_a',
            '16:00' => 'shift_b',
            default => null,
        };
    }

    public static function name(?string $pattern, ?string $start): ?string
    {
        $key = self::key($pattern, $start);

        return $key === null ? null : __('messages.shifts.names.'.$key);
    }

    public static function workedCounts(int $companyId, int $employeeId, string $from, string $to): array
    {
        $counts = [];
        $rows = Day::query()->where('company_id', $companyId)->where('employee_id', $employeeId)
            ->whereBetween('work_date', [$from, $to])->whereNull('superseded_by_run_id')
            ->where(function (Builder $days): void {
                $days->whereIn('status', ['present', 'field_work', 'rest_day_worked', 'holiday_worked'])
                    ->orWhere(fn (Builder $unresolved) => $unresolved->where('status', 'shift_unresolved')
                        ->where('presence_minutes', '>', 0));
            })
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(inputs, '$.work_pattern')) AS pattern, shift_start, COUNT(*) AS total")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(inputs, '$.work_pattern')), shift_start")->get();
        foreach ($rows as $row) {
            $key = self::key($row->pattern, $row->shift_start);
            if ($key !== null) {
                $counts[$key] = ($counts[$key] ?? 0) + (int) $row->total;
            }
        }

        return $counts;
    }

    public static function filterDays(Builder $query, array $shifts): Builder
    {
        if ($shifts === []) {
            return $query;
        }

        return $query->where(function (Builder $matching) use ($shifts): void {
            foreach ($shifts as $shift) {
                $matching->orWhere(function (Builder $day) use ($shift): void {
                    $pattern = in_array($shift, ['office', 'security'], true) ? $shift : 'shift';
                    $day->where('inputs->work_pattern', $pattern);
                    if ($shift === 'unresolved') {
                        // Absent and rest days of shift workers also have no start; only days whose shift could not be told count here.
                        $day->whereNull('shift_start')->where(fn (Builder $open) => $open->where('status', 'shift_unresolved')
                            ->orWhere('shift_source', 'unknown'));
                    } elseif ($pattern === 'shift') {
                        $day->where('shift_start', match ($shift) {
                            'night' => '00:00:00', 'shift_a' => '08:00:00', 'shift_b' => '16:00:00',
                        });
                    }
                });
            }
        });
    }

    public static function candidateStarts(?array $evidence): array
    {
        $starts = $evidence['candidate_starts'] ?? $evidence['candidate_shift_starts'] ?? [];
        if (! is_array($starts)) {
            return [];
        }

        $result = [];
        foreach ($starts as $start) {
            if (is_string($start) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $start)) {
                $result[] = substr($start, 0, 5);
            }
        }

        return array_values(array_unique($result));
    }

    public static function hours(?string $start, ?int $minutes): ?string
    {
        if ($start === null || $minutes === null) {
            return null;
        }

        $begin = CarbonImmutable::createFromFormat('!H:i:s', strlen($start) === 5 ? $start.':00' : $start);
        if ($begin === false) {
            return null;
        }

        return $begin->format('H:i').'–'.$begin->addMinutes($minutes)->format('H:i');
    }
}
