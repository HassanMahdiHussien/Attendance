<?php

namespace App\Support\Format;

use App\Enums\Outcome;

class OutcomeLabel
{
    public static function arabic(Outcome|string|null $outcome): string
    {
        $value = $outcome instanceof Outcome ? $outcome->value : $outcome;

        return match ($value) {
            'recommendation_only' => 'توصية دون عقوبة',
            'warning' => 'إنذار',
            'termination' => 'إنهاء خدمة',
            default => 'دون نتيجة بعد',
        };
    }
}
