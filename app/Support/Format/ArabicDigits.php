<?php

namespace App\Support\Format;

class ArabicDigits
{
    public static function convert(string|int $value): string
    {
        // The array form maps whole strings; the three-string form maps single bytes and corrupts UTF-8.
        return strtr((string) $value, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
