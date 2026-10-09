<?php

namespace App\Support\Search;

use App\Support\Text\ArabicText;

class SearchText
{
    public static function normalize(string $text): string
    {
        $text = ArabicText::normalize($text);
        $text = strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا', 'ة' => 'ه', 'ى' => 'ي', 'ؤ' => 'و', 'ئ' => 'ي']);

        return mb_strtolower($text, 'UTF-8');
    }
}
