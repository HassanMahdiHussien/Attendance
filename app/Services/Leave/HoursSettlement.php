<?php

namespace App\Services\Leave;

class HoursSettlement
{
    public static function applies(int $shortMinutes, int $sameDayLeaveMinutes): bool
    {
        return $sameDayLeaveMinutes > 0 && $shortMinutes <= $sameDayLeaveMinutes;
    }

    public static function withinTolerance(int $shortMinutes, int $uncoveredMinutes, int $limit): bool
    {
        return $shortMinutes === 0 && $limit > 0 && $uncoveredMinutes > 0 && $uncoveredMinutes <= $limit;
    }
}
