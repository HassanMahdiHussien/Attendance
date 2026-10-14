<?php

namespace App\LegalLibrary;

class ArticleSplitter
{
    private const ONES = [1 => ['الأول', 'الأولى', 'أولاً', 'اولاً', 'الحادي', 'الحادية', 'حادي', 'حادية'], 2 => ['الثاني', 'الثانية', 'ثانياً', 'ثاني', 'ثانية'], 3 => ['الثالث', 'الثالثة', 'ثالثاً', 'ثالث', 'ثالثة'], 4 => ['الرابع', 'الرابعة', 'رابعاً', 'رابع', 'رابعة'], 5 => ['الخامس', 'الخامسة', 'خامساً', 'خامس', 'خامسة'], 6 => ['السادس', 'السادسة', 'سادساً', 'سادس', 'سادسة'], 7 => ['السابع', 'السابعة', 'سابعاً', 'سابع', 'سابعة'], 8 => ['الثامن', 'الثامنة', 'ثامناً', 'ثامن', 'ثامنة'], 9 => ['التاسع', 'التاسعة', 'تاسعاً', 'تاسع', 'تاسعة']];

    private const TEENS = [10 => ['العاشر', 'العاشرة', 'عاشراً'], 11 => ['الحادي عشر', 'الحادية عشرة', 'حادي عشر'], 12 => ['الثاني عشر', 'الثانية عشرة', 'ثاني عشر'], 13 => ['الثالث عشر', 'الثالثة عشرة', 'ثالث عشر'], 14 => ['الرابع عشر', 'الرابعة عشرة', 'رابع عشر'], 15 => ['الخامس عشر', 'الخامسة عشرة', 'خامس عشر'], 16 => ['السادس عشر', 'السادسة عشرة', 'سادس عشر'], 17 => ['السابع عشر', 'السابعة عشرة', 'سابع عشر'], 18 => ['الثامن عشر', 'الثامنة عشرة', 'ثامن عشر'], 19 => ['التاسع عشر', 'التاسعة عشرة', 'تاسع عشر']];

    public function __construct(private TextNormalizer $normalizer) {}

    public function split(array $pages): array
    {
        $units = [];
        $article = null;
        $articleIndex = null;
        $heading = null;
        $clause = null;
        $clauseIndex = null;
        $clauseNumber = 0;
        $letterActive = false;
        foreach ($pages as $page => $text) {
            // Positions count non-empty lines only: pdftotext pads pages with blank lines, which would
            // otherwise push the page-number footer out of the header/footer window.
            $lines = array_values(array_filter(preg_split('/\R/u', $text), fn (string $raw): bool => trim($raw) !== ''));
            $last = count($lines) - 1;
            foreach ($lines as $index => $raw) {
                $line = trim($raw);
                if ($line === '' || $this->noise($line, $index, $last)) {
                    continue;
                }
                $clean = $this->normalizer->normalize($line);
                if (preg_match('/^(?:الباب|الفصل)\b/u', $clean)) {
                    $heading = $line;

                    continue;
                }
                if (preg_match('/^(?:المادة|مادة)\s*[-(]*\s*(.+)$/u', $clean, $match)) {
                    $number = $this->leadingNumber($match[1]);
                    // A wrapped line can begin with a cross-reference ("المادة (43) من هذا القانون"), never a heading.
                    $reference = preg_match('/^\(?\s*\d+\s*\)?\s*من\s+(?:هذا\s+)?(?:ال)?قانون/u', $match[1]);
                    if ($number !== null && ! $reference) {
                        $article = $number;
                        $clause = null;
                        $clauseIndex = null;
                        $clauseNumber = 0;
                        $letterActive = false;
                        $units[] = $this->unit($number, null, $line, (int) $page, $heading);
                        $articleIndex = count($units) - 1;
                        // The gazette often opens the first clause on the heading line ("المادة -129- أولاً: …"),
                        // so the letters and numbered items that follow belong to it.
                        if (preg_match('/^\(?\s*\d+\s*\)?\s*-?\s*(\S.*)$/u', $match[1], $rest)
                            && ($inline = $this->clauseMarker($rest[1], 0)) !== null && $inline[1] === 1) {
                            [$clause, $clauseNumber] = $inline;
                            $units[] = $this->unit($number, $number.'/'.$clause, $line, (int) $page, $heading);
                            $clauseIndex = count($units) - 1;
                        }

                        continue;
                    }
                }
                if ($article === null) {
                    continue;
                }
                // Numbered items inside a lettered clause ("(1)", "(2)") belong to that letter, not to the article.
                $marker = $letterActive && preg_match('/^\(?\d/u', $clean) ? null : $this->clauseMarker($clean, $clauseNumber);
                if ($marker !== null) {
                    [$clause, $clauseNumber] = $marker;
                    $letterActive = false;
                    $this->append($units[$articleIndex], $line, (int) $page);
                    $units[] = $this->unit($article, $article.'/'.$clause, $line, (int) $page, $heading);
                    $clauseIndex = count($units) - 1;

                    continue;
                }
                if ($clause !== null && preg_match('/^(?:\(([أابجدهوزحطيكلم])\)|([أابجدهوزحطيكلم])\s*[)\-–])\s*(.+)$/u', $clean, $match)) {
                    $letter = strtr($match[1] ?: $match[2], ['ا' => 'أ', 'ه' => 'هـ']);
                    $this->append($units[$articleIndex], $line, (int) $page);
                    $this->append($units[$clauseIndex], $line, (int) $page);
                    $units[] = $this->unit($article, $article.'/'.$clause.'/'.$letter, $line, (int) $page, $heading);
                    $letterActive = true;

                    continue;
                }
                if ($units !== []) {
                    $lastUnit = count($units) - 1;
                    $this->append($units[$lastUnit], $line, (int) $page);
                    if ($lastUnit !== $articleIndex) {
                        $this->append($units[$articleIndex], $line, (int) $page);
                    }
                    if ($clauseIndex !== null && $lastUnit !== $clauseIndex && $lastUnit !== $articleIndex) {
                        $this->append($units[$clauseIndex], $line, (int) $page);
                    }
                }
            }
        }
        $counts = array_count_values(array_map(fn (array $unit): string => $unit['article_number'].'|'.$unit['clause_path'], $units));
        foreach ($units as &$unit) {
            $key = $unit['article_number'].'|'.$unit['clause_path'];
            if ($counts[$key] > 1) {
                $unit['tags']['mismatch_reason'] = 'duplicate_heading';
            }
            if (isset($unit['tags']['continues_on'])) {
                $unit['tags']['continues_on'] = array_values($unit['tags']['continues_on']);
            }
        }

        return $units;
    }

    private function unit(string $number, ?string $path, string $text, int $page, ?string $heading): array
    {
        return ['article_number' => $number, 'clause_path' => $path, 'text' => $text,
            'page' => $page, 'tags' => array_filter(['heading' => $heading])];
    }

    private function append(array &$unit, string $line, int $page): void
    {
        $unit['text'] .= "\n".$line;
        if ($page !== $unit['page']) {
            $unit['tags']['continues_on'][$page] = $page;
        }
    }

    private function noise(string $line, int $index, int $last): bool
    {
        $line = $this->normalizer->normalize($line);
        if (preg_match('/^[\d\s\-–—().\/]+$/u', $line) || str_contains($line, 'الوقائع العراقية')) {
            return true;
        }
        if ($index > 2 && $index < $last - 2) {
            return false;
        }

        return str_contains($line, 'الوقائع العراقية') || preg_match_all('/(?=\p{Arabic})\p{L}/u', $line) < 4;
    }

    /**
     * A clause marker at the start of a line: an ordinal adverb (أولاً … عاشراً), a compound ordinal
     * (حادي عشر, ثاني وعشرون), or a single ordinal word or digits followed by a separator. Definite forms
     * (الأولى, الثالثة) are article-style words that also start ordinary sentences, so they never count.
     * Returns [canonical name, number] so both readings produce the same key whatever spelling they used.
     */
    private function clauseMarker(string $clean, int $previous): ?array
    {
        if (preg_match('/^\(?(\d{1,2})\s*[)\-–:]/u', $clean, $match)) {
            // A wrapped line can begin with a bracketed amount ("(15) خمسة عشر يوماً"); digits only open a clause
            // when they continue the article's own numbering.
            $number = (int) $match[1];

            return $number === 1 || $number === $previous + 1 ? [$this->canonicalClause($number), $number] : null;
        }
        if (preg_match('/^\(?([^\-–:()]{1,30}?)\s*[\-–:)]/u', $clean, $match)) {
            $candidate = trim($match[1]);
            $number = str_starts_with($candidate, 'ال') ? null : $this->articleNumber($candidate);
            if ($number !== null && ! preg_match('/^\d/u', $candidate) && count(preg_split('/\s+/u', $candidate)) <= 3) {
                return [$this->canonicalClause((int) $number), (int) $number];
            }
        }
        $words = preg_split('/\s+/u', $clean);
        for ($length = min(3, count($words)); $length >= 1; $length--) {
            $candidate = trim(implode(' ', array_slice($words, 0, $length)), ' -–:()');
            if ($candidate === '' || str_starts_with($candidate, 'ال') || preg_match('/^\d/u', $candidate)) {
                continue;
            }
            $number = $this->articleNumber($candidate);
            if ($number === null) {
                continue;
            }
            $rest = implode(' ', array_slice($words, $length));
            $separated = preg_match('/[-–:)]$/u', implode(' ', array_slice($words, 0, $length)))
                || preg_match('/^[-–:)]/u', $rest);
            $adverb = $length === 1 && str_ends_with($candidate, 'ا');
            if ($separated || $adverb || $length >= 2) {
                return [$this->canonicalClause((int) $number), (int) $number];
            }
        }

        return null;
    }

    private function canonicalClause(int $number): string
    {
        $adverbs = [1 => 'أولاً', 'ثانياً', 'ثالثاً', 'رابعاً', 'خامساً', 'سادساً', 'سابعاً', 'ثامناً', 'تاسعاً', 'عاشراً'];
        if (isset($adverbs[$number])) {
            return $adverbs[$number];
        }
        if (isset(self::TEENS[$number])) {
            return self::TEENS[$number][2];
        }
        $tens = [20 => 'عشرون', 30 => 'ثلاثون', 40 => 'أربعون', 50 => 'خمسون'];
        $ten = intdiv($number, 10) * 10;
        $one = $number % 10;
        if (! isset($tens[$ten])) {
            return (string) $number;
        }

        return $one === 0 ? $tens[$ten] : self::ONES[$one][$one === 1 ? 6 : 3].' و'.$tens[$ten];
    }

    private function articleNumber(string $value): ?string
    {
        $value = trim($this->normalizer->normalize($value), " -():\t");
        if (preg_match('/^\d{1,3}$/', $value)) {
            return (string) (int) $value;
        }
        foreach (self::ONES + self::TEENS as $number => $forms) {
            if (in_array($value, array_map($this->normalizer->normalize(...), $forms), true)) {
                return (string) $number;
            }
        }
        $tens = [20 => ['العشرون', 'عشرون', 'العشرين', 'عشرين'], 30 => ['الثلاثون', 'ثلاثون', 'الثلاثين', 'ثلاثين'], 40 => ['الأربعون', 'أربعون', 'الأربعين', 'أربعين'], 50 => ['الخمسون', 'خمسون', 'الخمسين', 'خمسين']];
        foreach ($tens as $ten => $forms) {
            if (in_array($value, $forms, true)) {
                return (string) $ten;
            }
            foreach (self::ONES as $one => $first) {
                foreach ($first as $word) {
                    foreach ($forms as $tensWord) {
                        if ($value === $this->normalizer->normalize($word).' و'.$tensWord || $value === $this->normalizer->normalize($word).' '.$tensWord) {
                            return (string) ($ten + $one);
                        }
                    }
                }
            }
        }

        return null;
    }

    private function leadingNumber(string $text): ?string
    {
        $words = preg_split('/[\s\-–:()]+/u', trim($text, ' -–:()'));
        for ($length = min(3, count($words)); $length >= 1; $length--) {
            $candidate = implode(' ', array_slice($words, 0, $length));
            $number = $this->articleNumber($candidate);
            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }
}
