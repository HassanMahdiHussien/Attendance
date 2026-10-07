<?php

namespace App\Documents;

use App\Enums\RelevanceCheck;
use App\Exceptions\IssuanceBlocked;
use App\Models\Citation;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\LegalUnit;

class CitationGate
{
    public function assertIssuable(Document $document): void
    {
        $case = $document->disciplinaryCase;
        $citations = $document->citations()->with('legalUnit.law')->get()->groupBy('legal_unit_id');
        foreach ($document->contents()->get() as $content) {
            foreach ([$content->body, $content->secondary_body] as $body) {
                if ($body === null) {
                    continue;
                }
                $prose = preg_replace('/\[\[cite:\d+\]\]/', '', $body);
                if (preg_match('/(?:المادة|مادة|Article)\s*[(（]?\s*[0-9٠-٩]+/iu', $prose)) {
                    throw new IssuanceBlocked('Article numbers must use citation markers.');
                }
                if (str_contains($prose, '[[cite:')) {
                    throw new IssuanceBlocked('Malformed citation marker.');
                }
                preg_match_all('/\[\[cite:(\d+)\]\]/', $body, $matches);
                foreach ($matches[1] as $id) {
                    $citation = $citations->get((int) $id)?->first();
                    if (! $citation || ! $this->valid($citation, $case)) {
                        $message = $citation?->legalUnit?->superseded_by_id !== null
                            ? 'النص القانوني صُحح — حدّث الاستشهاد'
                            : "Citation {$id} is not issuable.";
                        throw new IssuanceBlocked($message);
                    }
                }
            }
        }
    }

    private function valid(Citation $citation, DisciplinaryCase $case): bool
    {
        if (! LegalUnit::query()->citableFor($case)->whereKey($citation->legal_unit_id)->exists()) {
            return false;
        }
        if ($citation->relevance_check === RelevanceCheck::Supports) {
            return true;
        }

        return in_array($citation->relevance_check, [RelevanceCheck::Partial, RelevanceCheck::DoesNotSupport], true)
            && $citation->overridden && $citation->overridden_by !== null && $citation->overridden_at !== null;
    }
}
