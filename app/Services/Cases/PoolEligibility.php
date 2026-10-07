<?php

namespace App\Services\Cases;

use App\Enums\ApprovalDecision;
use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\CommitteeMandate;
use App\Models\Document;
use Illuminate\Support\Collection;

class PoolEligibility
{
    public function __construct(private ConflictClearance $conflicts) {}

    public function forDocument(Document $document): Collection
    {
        $case = $document->disciplinaryCase;
        if ($document->document_type !== DocumentType::Recommendations || $document->is_locked
            || ($document->version === 1 && $case->status !== CaseStatus::RecommendationsAwaitingApprovals)
            || ($document->version > 1 && ($case->status === CaseStatus::Draft || $document->status !== DocumentStatus::ReadyForReview))
            || ! $case->defense_letter_issued_at) {
            return collect();
        }
        $mandate = CommitteeMandate::query()->where('company_id', $case->company_id)
            ->inEffectOn($case->defense_letter_issued_at)->orderByDesc('effective_from')->first();
        if (! $mandate) {
            return collect();
        }
        $excluded = collect($this->conflicts->conflicts($case))->filter(fn (array $row) => $row['excluded'])
            ->pluck('member.id')->all();
        $approved = $document->approvals()->whereNull('invalidated_at')
            ->where('decision', ApprovalDecision::Approved->value)->pluck('role_category')->all();
        $decided = $document->approvals()->whereNull('invalidated_at')->pluck('approver_user_id')->all();

        return $mandate->poolMembers()->with('user')
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $case->branch_id))
            ->whereNotNull('user_id')->whereNotIn('id', $excluded)->get()
            ->filter(fn ($member) => $member->user?->is_active && $member->user->company?->is_active
                && ! in_array($member->role_type->value, $approved, true)
                && ! in_array($member->user_id, $decided, true))
            ->map(fn ($member) => ['member' => $member, 'user' => $member->user, 'category' => $member->role_type])
            ->values();
    }
}
