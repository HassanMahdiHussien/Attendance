<?php

namespace App\Documents;

use App\Exceptions\IssuanceBlocked;
use App\Models\Document;

class CitationRenderer
{
    public function replace(Document $document, string $body, string $language): string
    {
        $citations = $document->citations()->with('legalUnit.law')->get()->groupBy('legal_unit_id');

        return preg_replace_callback('/\[\[cite:(\d+)\]\]/', function ($match) use ($citations, $language): string {
            $unit = $citations->get((int) $match[1])?->first()?->legalUnit;
            if (! $unit) {
                throw new IssuanceBlocked("الاستشهاد {$match[1]} غير معروف.");
            }
            $law = $language === 'en' ? ($unit->law->name_en ?: $unit->law->name) : $unit->law->name;
            $clause = $unit->clause_path;
            $text = $language === 'en' ? "Article {$unit->article_number}" : "المادة ({$unit->article_number})";
            if ($clause) {
                $text .= $language === 'en' ? $this->englishClause($clause) : $this->arabicClause($clause);
            }

            // Bracketed (product owner 2026-10-16): the AI places a marker straight after a clause, and a bare
            // "…the employer Article 42(2)…" reads as broken grammar.
            return '('.$text.($language === 'en' ? " of {$law}" : " من {$law}").')';
        }, $body);
    }

    // clause_path starts with the article number ("42/ثانياً"), which the text already states.
    private function arabicClause(string $path): string
    {
        $parts = explode('/', $path);
        array_shift($parts);
        $clause = implode('/', array_map('trim', $parts));

        return $clause === '' ? '' : " البند ({$clause})";
    }

    private function englishClause(string $path): string
    {
        $parts = explode('/', $path);
        array_shift($parts);
        $ordinals = ['أولاً' => 1, 'ثانياً' => 2, 'ثالثاً' => 3, 'رابعاً' => 4,
            'خامساً' => 5, 'سادساً' => 6, 'سابعاً' => 7, 'ثامناً' => 8,
            'تاسعاً' => 9, 'عاشراً' => 10, 'ثامن عشر' => 18];
        $letters = array_flip(['أ', 'ب', 'ج', 'د', 'هـ', 'و', 'ز', 'ح', 'ط', 'ي', 'ك', 'ل', 'م']);
        $suffix = '';
        foreach ($parts as $part) {
            $part = trim($part);
            $number = $ordinals[$part] ?? null;
            if ($number === null && isset($letters[$part])) {
                $number = chr(97 + $letters[$part]);
            }
            if ($number === null) {
                return " clause {$path}";
            }
            $suffix .= "({$number})";
        }

        return $suffix;
    }
}
