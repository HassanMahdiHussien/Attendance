<?php

namespace App\Support\Format;

use App\Documents\IraqiDate;
use App\Enums\CaseStatus;
use App\Enums\Outcome;
use App\Support\Text\ArabicText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class Ui
{
    public static function number(string|int|float $value): string
    {
        return ArabicText::westernDigits((string) $value);
    }

    public static function date(CarbonInterface|string|null $date, ?string $format = null): string
    {
        if ($date === null) {
            return '—';
        }

        $local = CarbonImmutable::parse($date)->setTimezone('Asia/Baghdad');

        if ($format !== null) {
            return self::number($local->format($format));
        }

        return app()->getLocale() === 'ar'
            ? IraqiDate::format($local, 'ar')
            : $local->format('j M Y');
    }

    public static function outcome(Outcome|string|null $outcome): string
    {
        $value = $outcome instanceof Outcome ? $outcome->value : $outcome;

        return match ($value) {
            'recommendation_only' => __('Recommendation without penalty'),
            'warning' => __('Warning'),
            'termination' => __('Termination'),
            default => __('No outcome yet'),
        };
    }

    public static function stage(CaseStatus $status): string
    {
        return self::stageLabel(StageIndicator::step($status));
    }

    public static function stageLabel(int $stage): string
    {
        return match ($stage) {
            1 => __('Case opened'),
            2 => __('Defense letter'),
            3 => __('Employee response'),
            4 => __('Committee'),
            5 => __('Decision'),
        };
    }

    public static function remaining(int $days): string
    {
        return match ($days) {
            0 => __('Due today'),
            1 => __('One day remaining'),
            2 => __('Two days remaining'),
            default => __(':days days remaining', ['days' => self::number($days)]),
        };
    }
}
