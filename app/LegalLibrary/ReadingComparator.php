<?php

namespace App\LegalLibrary;

class ReadingComparator
{
    public function __construct(private TextNormalizer $normalizer) {}

    public function compare(array $a, array $b, bool $illegible = false): array
    {
        $left = $this->index($a);
        $right = $this->index($b);
        $structureA = $this->structure($a);
        $structureB = $this->structure($b);
        $result = [];
        foreach (array_unique([...array_keys($left), ...array_keys($right)]) as $key) {
            $leftCopies = $left[$key] ?? [];
            $rightCopies = $right[$key] ?? [];
            for ($copy = 0; $copy < max(count($leftCopies), count($rightCopies)); $copy++) {
                $first = $leftCopies[$copy] ?? null;
                $second = $rightCopies[$copy] ?? null;
                $unit = $first ?? $second;
                $article = $unit['article_number'];
                $reason = $first === null || $second === null ? 'one_reading_only' : null;
                if (count($leftCopies) > 1 || count($rightCopies) > 1 || isset($first['tags']['mismatch_reason']) || isset($second['tags']['mismatch_reason'])) {
                    $reason = 'duplicate_heading';
                }
                if ($reason === null && ($illegible || str_contains($first['text'], '[؟]') || str_contains($second['text'], '[؟]'))) {
                    $reason = 'illegible';
                }
                if ($reason === null && ($structureA[$article] ?? []) !== ($structureB[$article] ?? [])) {
                    $reason = 'structure_differs';
                }
                if ($reason === null && $this->normalizer->normalize($first['text']) !== $this->normalizer->normalize($second['text'])) {
                    $reason = 'text_differs';
                }
                $unit['extraction_a'] = $first['text'] ?? null;
                $unit['extraction_b'] = $second['text'] ?? null;
                $unit['status'] = $reason === null ? 'verified_auto' : 'mismatch';
                if ($reason !== null) {
                    $unit['tags']['mismatch_reason'] = $reason;
                }
                $result[] = $unit;
            }
        }

        return $result;
    }

    private function index(array $units): array
    {
        $indexed = [];
        foreach ($units as $unit) {
            $indexed[$unit['article_number'].'|'.$unit['clause_path']][] = $unit;
        }

        return $indexed;
    }

    private function structure(array $units): array
    {
        $structure = [];
        foreach ($units as $unit) {
            $structure[$unit['article_number']][] = $unit['clause_path'];
        }
        foreach ($structure as &$paths) {
            sort($paths);
        }

        return $structure;
    }
}
