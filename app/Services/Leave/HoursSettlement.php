<?php

namespace App\Services\Leave;

class HoursSettlement
{
    public static function applies(int $shortMinutes, int $sameDayLeaveMinutes): bool
    {
        return $sameDayLeaveMinutes > 0 && $shortMinutes <= $sameDayLeaveMinutes;
    }
}
