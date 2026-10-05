<?php

namespace App\Support\Format;

class ArabicDigits
{
    public static function convert(string|int $value): string
    {
        return strtr((string) $value, '0123456789', '٠١٢٣٤٥٦٧٨٩');
    }
}
