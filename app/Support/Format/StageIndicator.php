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
        return self::stageLabel(self::step($status));
    }

    public static function stageLabel(int $stage): string
    {
        return match ($stage) {
            1 => __('Case opened'), 2 => __('Defense letter'), 3 => __('Employee response'),
            4 => __('Committee'), 5 => __('Decision'),
        };
    }

    public static function statusesFor(int $stage): array
    {
        return array_values(array_map(
            fn (CaseStatus $status): string => $status->value,
            array_filter(CaseStatus::cases(), fn (CaseStatus $status): bool => self::step($status) === $stage),
        ));
    }

    public static function remaining(int $days): string
    {
        return match ($days) {
            0 => __('Due today'),
            1 => __('One day remaining'),
            2 => __('Two days remaining'),
            default => __(':days days remaining', ['days' => Ui::number($days)]),
        };
    }
}
