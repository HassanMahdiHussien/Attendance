<?php

namespace App\LegalLibrary;

use App\Models\Law;
use App\Models\LegalUnit;

class TextLayerRepair
{
    public function __construct(private TextNormalizer $normalizer) {}

    /** @return array{text: string, counts: array<string, int>} */
    public function repair(string $text, Law $law): array
    {
        $counts = [];
        $fixes = $law->text_layer_fixes ?? [];

        if (in_array('mirrored_brackets', $fixes, true)) {
            $counts['mirrored_brackets'] = 0;
            $text = preg_replace_callback('/[^\r\n]+/u', function (array $match) use (&$counts): string {
                $line = $match[0];
                if (! str_contains($line, '(') && ! str_contains($line, ')')) {
                    return $line;
                }
                $counts['mirrored_brackets'] += preg_match_all('/[()][\p{L}\p{N}]+|[\p{L}\p{N}]+[()]/u', $line);

                return strtr($line, ['(' => ')', ')' => '(']);
            }, $text);
        }

        if (in_array('kaf_from_alef_madda', $fixes, true)) {
            $counts['kaf_from_alef_madda'] = 0;
            $vocabulary = $this->vocabulary($law);
            if ($vocabulary !== []) {
                $text = preg_replace_callback('/[\p{L}\p{M}]+/u', function (array $match) use ($vocabulary, &$counts): string {
                    $word = $match[0];
                    $occurrences = mb_substr_count($word, 'آ');
                    if ($occurrences === 0 || $occurrences > 3
                        || isset($vocabulary[$this->normalizer->normalize($word)])) {
                        return $word;
                    }

                    $candidates = [];
                    $letters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
                    $positions = array_keys(array_filter($letters, fn (string $letter): bool => $letter === 'آ'));
                    for ($mask = 1; $mask < (1 << $occurrences); $mask++) {
                        $candidate = $letters;
                        foreach ($positions as $index => $position) {
                            if ($mask & (1 << $index)) {
                                $candidate[$position] = 'ك';
                            }
                        }
                        $candidate = implode('', $candidate);
                        if (isset($vocabulary[$this->normalizer->normalize($candidate)])) {
                            $candidates[$candidate] = true;
                        }
                    }
                    if (count($candidates) !== 1) {
                        return $word;
                    }
                    $counts['kaf_from_alef_madda']++;

                    return array_key_first($candidates);
                }, $text);
            }
        }

        return ['text' => $text, 'counts' => $counts];
    }

    /** @return array<string, true> */
    private function vocabulary(Law $law): array
    {
        $words = [];
        $units = LegalUnit::query()->where('law_id', '!=', $law->id)
            ->whereIn('status', ['verified_auto', 'verified_human', 'verified_text'])
            ->whereNull('superseded_by_id')
            ->whereHas('law', fn ($query) => $query->where('kind', 'statute'))
            ->select('official_text')->cursor();

        foreach ($units as $unit) {
            foreach (preg_split('/[\s\p{P}\p{S}]+/u', $this->normalizer->normalize($unit->official_text), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                $words[$word] = true;
            }
        }

        return $words;
    }
}
