<?php

namespace App\Support\Access;

use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\User;
use App\Services\Cases\PoolEligibility;

/**
 * Whether the "اللجنة" navigation item should appear: the user is an eligible approver for at least
 * one recommendations document awaiting approval. Uses the same eligibility rule as the approval
 * service so the menu never offers work the service would refuse.
 */
class CommitteeNav
{
    public static function hasPending(?User $user): bool
    {
        if ($user === null || $user->company_id === null) {
            return false;
        }

        return Document::query()
            ->where('document_type', DocumentType::Recommendations->value)
            ->where('is_locked', false)
            ->where(fn ($query) => $query
                ->whereHas('disciplinaryCase', fn ($case) => $case->where('status', CaseStatus::RecommendationsAwaitingApprovals->value))
                ->orWhere(fn ($correction) => $correction->where('version', '>', 1)->where('status', DocumentStatus::ReadyForReview->value)))
            ->get()
            ->contains(fn (Document $document) => app(PoolEligibility::class)->forDocument($document)
                ->contains(fn (array $row) => $row['user']->id === $user->id));
    }
}
