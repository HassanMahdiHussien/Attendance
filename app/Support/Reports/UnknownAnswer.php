<?php

namespace App\Support\Reports;

class UnknownAnswer
{
    public static function matches(string $value): bool
    {
        $text = mb_strtolower(trim(preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $value)));
        $text = str_replace(['أ', 'إ', 'آ', 'ى', 'ة'], ['ا', 'ا', 'ا', 'ي', 'ه'], $text);
        $text = trim(preg_replace('/[\p{P}\s]+/u', ' ', $text));
        // Only a short answer that is essentially "I don't know" counts: a real description may quote someone
        // saying «لا أعرف» and must never be dropped.
        if (count(explode(' ', $text)) > 6) {
            return false;
        }

        return preg_match('/\b(?:i (?:do not|don.t) know|not sure|no idea|unknown)\b/iu', $text) === 1
            || preg_match('/(?:ما\s*اعرف|ممعرف|مامعرف|ما\s*ادري|مادري|لا\s*اعرف|ماكو\s*فكره|غير\s*معروف|ما\s*متاكد)/u', $text) === 1;
    }
}
