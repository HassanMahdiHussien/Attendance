<?php

namespace App\Ai;

class OutcomeKeywordFlag
{
    public function flagged(array $languages, ?string $outcome): bool
    {
        $words = [
            'warning' => '/(?:إنذار|انذار|warning)/iu',
            'termination' => '/(?:إنهاء الخدمة|انهاء الخدمة|فصل|termination|dismissal|dismiss)/iu',
        ];
        $text = implode(' ', array_map(fn ($row) => ($row['body'] ?? '').' '.($row['secondary_body'] ?? ''), $languages));
        foreach ($words as $kind => $pattern) {
            if ($kind !== $outcome && preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }
}
