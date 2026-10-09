<?php

namespace App\LegalLibrary;

use App\Support\Text\ArabicText;

class TextNormalizer
{
    public function normalize(string $text): string
    {
        $text = ArabicText::normalize($text);
        $text = strtr($text, ['،' => ',', '؛' => ';', '؟' => '?']);
        // Spacing that never changes meaning but differs between PDF text layers and other readings:
        // spaces around a clause hyphen or colon ("أولا -تحفظ" / "أولا- تحفظ") and a detached
        // conjunction ("و يعمل"), which is always written attached to the next word.
        $text = preg_replace('/\s*([\-–:.])\s*/u', '$1', $text);
        // Right-to-left extraction mirrors brackets around numbers: "()2" or ")2(" for "(2)".
        $text = preg_replace('/\(\)\s*(\d+)|\)\s*(\d+)\s*\(/u', '($1$2)', $text);
        $text = preg_replace('/(?<=^|\s)و\s+(?=\p{Arabic})/u', 'و', $text);

        return trim($text);
    }
}
