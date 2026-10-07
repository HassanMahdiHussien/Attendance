<?php

namespace App\Documents;

use App\Enums\RelevanceCheck;
use App\Exceptions\IssuanceBlocked;
use App\Models\Citation;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\LegalUnit;
use App\Services\Documents\CitationSync;

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
                    throw new IssuanceBlocked('يجب إدراج أرقام المواد عبر علامة الاستشهاد.');
                }
                if (str_contains($prose, '[[cite:')) {
                    throw new IssuanceBlocked('علامة الاستشهاد غير صحيحة.');
                }
                foreach (CitationSync::claims($body) as $claim) {
                    $id = $claim['legal_unit_id'];
                    $citation = $citations->get($id)?->first(fn (Citation $row) => $content->language_code !== 'ar'
                        || $row->claim_hash === $claim['claim_hash'] || ($document->is_locked && $row->claim_hash === null));
                    if (! $citation || ! $this->valid($citation, $case)) {
                        $message = $citation?->legalUnit?->superseded_by_id !== null
                            ? 'النص القانوني صُحح — حدّث الاستشهاد'
                            : 'الاستشهاد بالمادة ('.(LegalUnit::withoutGlobalScopes()->find($id)?->article_number ?? $id)
                                .') يحتاج إلى فحص داعم أو تجاوز مسبب.';
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

        return $citation->overridden && $citation->overridden_by !== null && $citation->overridden_at !== null
            && filled($citation->override_reason);
    }
}
