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
                throw new IssuanceBlocked("Unknown citation {$match[1]}.");
            }
            $law = $unit->law->name;
            $clause = $unit->clause_path;
            $text = $language === 'en' ? "Article {$unit->article_number}" : "المادة ({$unit->article_number})";
            if ($clause) {
                $text .= $language === 'en' ? " clause {$clause}" : " البند ({$clause})";
            }

            return $text.($language === 'en' ? " of {$law}" : " من {$law}");
        }, $body);
    }
}
