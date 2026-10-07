<?php

namespace App\LegalLibrary;

use Normalizer;

class TextLayerProbe
{
    // Common standalone Arabic words of one or two letters; any other short token is a fragment.
    private const SHORT_WORDS = ['و', 'في', 'من', 'عن', 'أو', 'او', 'لا', 'ما', 'أن', 'ان', 'إن', 'لم', 'لن', 'قد',
        'هو', 'هي', 'كل', 'به', 'له', 'لها', 'بها', 'ذا', 'إذ', 'اذ', 'مع', 'ثم', 'بل', 'كي', 'يا', 'أي', 'اي'];

    public function usable(string $text): bool
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $arabic = preg_match_all('/(?=\p{Arabic})\p{L}/u', $text);
        $letters = preg_match_all('/\p{L}/u', $text);
        $bad = preg_match_all('/[\x{FFFD}\x{E000}-\x{F8FF}]/u', $text);

        return $arabic >= 150 && $letters > 0 && $arabic / $letters >= 0.6
            && $bad / max(1, mb_strlen($text)) < 0.01
            && $this->fragmentRatio($text) < 0.2;
    }

    /**
     * Share of Arabic words that are short fragments. Some PDF producers emit spaces inside words
     * ("الث الث ة"); such a text layer breaks article headings, so the page must be read by OCR instead.
     */
    public function fragmentRatio(string $text): float
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        preg_match_all('/[\x{0621}-\x{064A}\x{0671}-\x{06D3}]+/u', $text, $matches);
        $words = $matches[0];
        if ($words === []) {
            return 0.0;
        }
        $fragments = array_filter($words, fn (string $word): bool => mb_strlen($word) <= 2
            && ! in_array($word, self::SHORT_WORDS, true));

        return count($fragments) / count($words);
    }
}
