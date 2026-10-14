<?php

namespace App\LegalLibrary;

use App\Support\Text\ArabicText;

class TextNormalizer
{
    public function normalize(string $text): string
    {
        // Gazette typesetting, not wording: zero-width joiners, a lone Arabic-Indic zero used as the full stop, and a
        // dash drawn as a run of tatweel or as an en/em dash. Resolved before tatweel is stripped so the dash survives.
        $text = preg_replace('/[\x{200C}\x{200D}]/u', '', $text);
        $text = preg_replace('/(?<![\d\x{0660}-\x{0669}(])\x{0660}(?![\d\x{0660}-\x{0669})])/u', '.', $text);
        // A tatweel is a dash when it stands alone, runs two or more before a space, or follows a one-letter clause
        // marker ("أـ"); a single kashida ending a word is only decoration and is stripped below.
        $text = preg_replace('/(?:(?<=^|\s)\x{0640}+|\x{0640}{2,}|(?:(?<=^\p{Arabic})|(?<=\s\p{Arabic}))\x{0640}+)(?=\s|$)/um', '-', $text);
        $text = ArabicText::normalize($text);
        $text = strtr($text, ['،' => ',', '؛' => ';', '؟' => '?', '–' => '-', '—' => '-']);
        // Spacing that never changes meaning but differs between PDF text layers and other readings:
        // spaces around a clause hyphen or colon ("أولا -تحفظ" / "أولا- تحفظ") and a detached
        // conjunction ("و يعمل"), which is always written attached to the next word.
        $text = preg_replace('/\s*([\-–:.])\s*/u', '$1', $text);
        // Right-to-left extraction mirrors brackets around numbers: "()2" or ")2(" for "(2)".
        $text = preg_replace('/\(\)\s*(\d+)|\)\s*(\d+)\s*\(/u', '($1$2)', $text);
        $text = preg_replace(['/\(\s+/u', '/\s+\)/u'], ['(', ')'], $text);
        $text = preg_replace('/(?<=^|\s)و\s+(?=\p{Arabic})/u', 'و', $text);

        return trim($text);
    }

    /**
     * The form two readings are compared in: words and numbers only. Separators and punctuation (dashes, commas,
     * colons, full stops, brackets) are dropped because right-to-left text layers move them across words and digits
     * ("واحدة ،اذا", "( )14من"), and a missing space between a number and a word is ignored. A space between two
     * numbers is kept, so "1 50" never matches "150".
     */
    public function comparable(string $text): string
    {
        $text = preg_replace('/[\-–,:;.()«»"\'!?]+/u', ' ', $this->normalize($text));
        $text = preg_replace(['/(?<=\d)\s*(?=\p{Arabic})/u', '/(?<=\p{Arabic})\s*(?=\d)/u'], [' ', ' '], $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
