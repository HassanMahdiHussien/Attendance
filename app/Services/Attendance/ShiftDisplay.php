<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

class ShiftDisplay
{
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
