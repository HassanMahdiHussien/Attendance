<?php

namespace App\LegalLibrary;

/**
 * Removes running headers and footers (gazette title, issue number, page number, date) from page readings.
 * A line counts as furniture when, with its digits masked, it sits in the first or last three lines of at least
 * 30% of the pages; such lines are then removed wherever they appear, so both readings are compared on body text only.
 */
class PageFurniture
{
    private const EDGE_LINES = 3;

    private const MIN_SHARE = 0.3;

    private const MIN_PAGES = 3;

    /**
     * @param  array<int, string>  $pages  page number => reading text
     * @return array<int, string>
     */
    public function strip(array $pages): array
    {
        if (count($pages) < self::MIN_PAGES) {
            return $pages;
        }
        $counts = [];
        foreach ($pages as $text) {
            $lines = $this->lines($text);
            $edges = array_merge(array_slice($lines, 0, self::EDGE_LINES), array_slice($lines, -self::EDGE_LINES));
            // A heading standing alone ("المادة 32") also repeats near page edges once its digits are masked; it is
            // structure, never furniture.
            $edges = array_filter($edges, fn (string $line) => ! preg_match('/^\s*(?:المادة|مادة|الفصل|الباب|الفرع)\b/u', $line));
            foreach (array_unique(array_map(fn (string $line) => $this->key($line), $edges)) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        $furniture = array_keys(array_filter($counts, fn (int $count) => $count >= self::MIN_SHARE * count($pages)));
        if ($furniture === []) {
            return $pages;
        }
        $furniture = array_flip($furniture);

        return array_map(fn (string $text) => implode("\n", array_filter($this->lines($text),
            fn (string $line) => ! isset($furniture[$this->key($line)]))), $pages);
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        return array_values(array_filter(preg_split('/\R/u', $text), fn (string $line) => trim($line) !== ''));
    }

    private function key(string $line): string
    {
        return preg_replace(['/[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]+/u', '/\s+/u'], ['#', ' '], trim($line));
    }
}
