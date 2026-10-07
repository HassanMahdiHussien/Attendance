<?php

namespace App\LegalLibrary;

use Normalizer;

class TextNormalizer
{
    public function normalize(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{0640}\x{064B}-\x{065F}\x{0670}]/u', '', $text);
        $text = strtr($text, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '،' => ',', '؛' => ';', '؟' => '?']);

        $text = preg_replace('/\s+/u', ' ', $text);
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
