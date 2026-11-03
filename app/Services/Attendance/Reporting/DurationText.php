<?php

namespace App\Services\Attendance\Reporting;

class DurationText
{
    public function format(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '';
        $absolute = abs($minutes);
        $hours = intdiv($absolute, 60);
        $remainder = $absolute % 60;

        if ($hours === 0 && $remainder > 0) {
            return $sign.__('messages.duration_text.minutes', ['minutes' => $remainder]);
        }

        if ($remainder === 0) {
            return $sign.__('messages.duration_text.hours', ['hours' => $hours]);
        }

        return $sign.__('messages.duration_text.hours_minutes', ['hours' => $hours, 'minutes' => $remainder]);
    }
}
