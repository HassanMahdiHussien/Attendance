<?php

namespace App\Documents;

use App\Enums\LawApprovalStatus;
use App\Enums\LawKind;
use App\Enums\LegalUnitStatus;
use App\Enums\RelevanceCheck;
use App\Exceptions\IssuanceBlocked;
use App\Models\Citation;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

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
                    if (! $citation || ! $this->valid($citation, $case->company_id, $case->jurisdiction_at_opening->value)) {
                        throw new IssuanceBlocked("Citation {$id} is not issuable.");
                    }
                }
            }
        }
    }

    private function valid(Citation $citation, int $companyId, string $jurisdiction): bool
    {
        $unit = $citation->legalUnit;
        $law = $unit?->law;
        if (! $unit || ! $law || ! in_array($unit->status, [LegalUnitStatus::VerifiedAuto, LegalUnitStatus::VerifiedHuman], true)
            || $unit->superseded_by_id !== null) {
            return false;
        }
        if ($law->kind === LawKind::Statute) {
            if (! DB::table('law_jurisdictions')->where('law_id', $law->id)->where('jurisdiction', $jurisdiction)->exists()) {
                return false;
            }
        } elseif ($law->kind !== LawKind::InternalRegulation || (int) $law->company_id !== $companyId
            || $law->approval_status !== LawApprovalStatus::Approved) {
            return false;
        }
        if ($citation->relevance_check === RelevanceCheck::Supports) {
            return true;
        }

        return in_array($citation->relevance_check, [RelevanceCheck::Partial, RelevanceCheck::DoesNotSupport], true)
            && $citation->overridden && $citation->overridden_by !== null && $citation->overridden_at !== null;
    }
}
