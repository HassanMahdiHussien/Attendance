<?php

namespace App\Documents;

use App\Ai\RegulationComparisons;
use App\Enums\RelevanceCheck;
use App\Exceptions\IssuanceBlocked;
use App\Models\Citation;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\LegalUnit;
use App\Services\Documents\CitationSync;
use App\Services\Documents\RegulationExclusions;

class CitationGate
{
    /**
     * A draft preview skips only the review requirement (supporting check or reasoned override): HR has to read the
     * draft to make that decision. Every structural rule still applies, and issuance always passes $draft = false.
     */
    public function assertIssuable(Document $document, bool $draft = false, ?array $contents = null): void
    {
        $case = $document->disciplinaryCase;
        if ($document->regulationComparison && ! in_array($document->regulationComparison->status, ['confirmed', 'skipped'], true)) {
            throw new IssuanceBlocked(__('messages.regulation.confirm_first'));
        }
        $citations = $document->citations()->with('legalUnit.law')->get()->groupBy('legal_unit_id');
        $exclusions = app(RegulationExclusions::class);
        $excluded = $exclusions->for($document);
        $blockedCitation = $document->citations()->whereIn('legal_unit_id', $excluded)->first();
        if ($blockedCitation) {
            throw new IssuanceBlocked(__($exclusions->refusalKey($document, $blockedCitation->legal_unit_id)));
        }
        foreach ($document->contents()->get() as $content) {
            $override = $contents[$content->language_code] ?? [];
            foreach ([$override['body'] ?? $content->body, $override['secondary_body'] ?? $content->secondary_body] as $body) {
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
                    if (in_array($id, $excluded, true)) {
                        throw new IssuanceBlocked(__($exclusions->refusalKey($document, $id)));
                    }
                    // Unsaved wording has no synced claim hash; the saved issuance path still requires one.
                    $citation = $citations->get($id)?->first(fn (Citation $row) => $content->language_code !== 'ar'
                        || $row->claim_hash === $claim['claim_hash'] || ($document->is_locked && $row->claim_hash === null)
                        || ($draft && $contents !== null));
                    if (! $citation || ! $this->valid($citation, $case, $draft)) {
                        $message = $citation?->legalUnit?->superseded_by_id !== null
                            ? 'النص القانوني صُحح — حدّث الاستشهاد'
                            : 'الاستشهاد بالمادة ('.(LegalUnit::withoutGlobalScopes()->find($id)?->article_number ?? $id)
                                .') يحتاج إلى فحص داعم أو تجاوز مسبب.';
                        throw new IssuanceBlocked($message);
                    }
                }
            }
        }
        if ($document->document_type->value === 'decision_letter' && ! $document->regulation_comparison_id
            && app(RegulationComparisons::class)->required($case)) {
            throw new IssuanceBlocked(__('messages.regulation.confirm_first'));
        }
    }

    private function valid(Citation $citation, DisciplinaryCase $case, bool $draft = false): bool
    {
        if (! LegalUnit::query()->citableFor($case)->whereKey($citation->legal_unit_id)->exists()) {
            return false;
        }
        if ($draft || $citation->relevance_check === RelevanceCheck::Supports) {
            return true;
        }

        return $citation->overridden && $citation->overridden_by !== null && $citation->overridden_at !== null
            && filled($citation->override_reason);
    }
}
