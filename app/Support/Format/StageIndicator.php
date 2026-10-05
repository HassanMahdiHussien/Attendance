<?php

namespace App\Support\Format;

use App\Enums\CaseStatus;

class StageIndicator
{
    public static function step(CaseStatus $status): int
    {
        $ordered = CaseStatus::cases();
        $index = array_search($status, $ordered, true);

        return match (true) {
            $status === CaseStatus::ArchivedFinal => 5,
            $index >= array_search(CaseStatus::DecisionIssued, $ordered, true) => 5,
            $index >= array_search(CaseStatus::CommitteeSessionHeld, $ordered, true) => 4,
            $index >= array_search(CaseStatus::ResponseReceived, $ordered, true) => 3,
            $index >= array_search(CaseStatus::DefenseLetterIssued, $ordered, true) => 2,
            default => 1,
        };
    }

    public static function label(CaseStatus $status): string
    {
        return match (self::step($status)) {
            1 => 'فتح الحالة', 2 => 'خطاب الدفاع', 3 => 'رد الموظف',
            4 => 'اللجنة', 5 => 'القرار',
        };
    }

    public static function remaining(int $days): string
    {
        return match ($days) {
            0 => 'باقي اليوم',
            1 => 'باقي يوم',
            2 => 'باقي يومان',
            default => 'باقي '.ArabicDigits::convert($days).' أيام',
        };
    }
}
