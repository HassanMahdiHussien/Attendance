<?php

namespace App\Ai;

use App\Enums\DocumentType;

class DraftValidator
{
    public function validate(array $output, array $languages, DocumentType $type, array $offeredIds, array $payload): array
    {
        $errors = [];
        $rows = $output['languages'] ?? null;
        if (! is_array($rows) || array_diff($languages, array_keys($rows)) || array_diff(array_keys($rows), $languages)) {
            return ['Missing or unexpected language.'];
        }
        $arabicIds = null;
        $input = json_encode($payload, JSON_UNESCAPED_UNICODE);
        preg_match_all('/[\p{N}]+/u', $input, $inputNumbers);
        $allowedNumbers = array_unique($inputNumbers[0]);
        foreach ($languages as $language) {
            if (! is_array($rows[$language])) {
                $errors[] = "Invalid {$language} sections.";

                continue;
            }
            $sections = [$rows[$language]['body'] ?? null];
            if ($type === DocumentType::Recommendations) {
                $sections[] = $rows[$language]['secondary_body'] ?? null;
            }
            $ids = [];
            foreach ($sections as $body) {
                if (! is_string($body) || blank($body) || mb_strlen($body) > 12000) {
                    $errors[] = "Invalid or empty {$language} body.";

                    continue;
                }
                preg_match_all('/\[\[cite:(\d+)\]\]/', $body, $matches);
                $ids = array_merge($ids, array_map('intval', $matches[1]));
                if (array_diff($ids, $offeredIds)) {
                    $errors[] = "Unoffered citation in {$language}.";
                }
                $prose = preg_replace('/\[\[cite:\d+\]\]/', '', $body);
                if (preg_match('/\[\[|\]\]/u', $prose) || preg_match('/(?:المادة|مادة|Article)\s*[(（]?\s*[\p{N}]+/iu', $prose)) {
                    $errors[] = "Bare or malformed citation in {$language}.";
                }
                if (preg_match('/[{}]/u', $prose)) {
                    $errors[] = "Placeholder in {$language}.";
                }
                if (str_contains($body, '⟦رقم⟧') || str_contains($body, '⟦بريد⟧')) {
                    $errors[] = "Masked data in {$language}.";
                }
                preg_match_all('/[\p{N}]+/u', $prose, $numbers);
                foreach ($numbers[0] as $number) {
                    if (! in_array($number, $allowedNumbers, true)) {
                        $errors[] = "Invented number in {$language}.";
                    }
                }
                $letters = preg_match_all('/\p{L}/u', $prose);
                $expected = preg_match_all($language === 'en' ? '/\p{Latin}/u' : '/\p{Arabic}/u', $prose);
                if ($letters === 0 || $expected / $letters < 0.8) {
                    $errors[] = "Wrong script in {$language}.";
                }
            }
            sort($ids);
            if ($language === 'ar') {
                $arabicIds = $ids;
            } elseif ($ids !== $arabicIds) {
                $errors[] = "Citation occurrences differ in {$language}.";
            }
        }
        if (! is_array($output['notes'] ?? null) || count($output['notes']) > 10
            || collect($output['notes'])->contains(fn ($note) => ! is_string($note) || mb_strlen($note) > 240)) {
            $errors[] = 'Invalid notes.';
        }

        return array_values(array_unique($errors));
    }
}
