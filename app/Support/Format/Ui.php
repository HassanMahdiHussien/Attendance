<?php

namespace App\Support\Format;

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
            ? $local->day.' '.__('messages.ui.months.'.$local->month).' '.$local->year
            : $local->format('j M Y');
    }
}
