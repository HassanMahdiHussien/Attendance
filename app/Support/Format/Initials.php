<?php

namespace App\Support\Format;

class Initials
{
    public static function for(string $name): string
    {
        $words = preg_split('/\s+/u', trim($name)) ?: [];

        return implode('.', array_map(fn (string $word) => mb_substr($word, 0, 1), array_slice($words, 0, 2)));
    }
}
