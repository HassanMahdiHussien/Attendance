<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

class ShiftDisplay
{
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
