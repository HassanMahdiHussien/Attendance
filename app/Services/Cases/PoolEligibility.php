<?php

namespace App\Services\Cases;

use App\Enums\ApprovalDecision;
use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Models\CommitteeMandate;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Collection;

class PoolEligibility
{
    public function __construct(private ConflictClearance $conflicts) {}

    public function pendingFor(User $user): Collection
    {
        if ($user->company_id === null || ! $user->is_active) {
            return collect();
        }

        $documents = Document::query()->where('document_type', DocumentType::Recommendations->value)
            ->where('is_locked', false)
            ->where(fn ($query) => $query
                ->whereHas('disciplinaryCase', fn ($case) => $case->where('status', CaseStatus::RecommendationsAwaitingApprovals->value))
                ->orWhere(fn ($correction) => $correction->where('version', '>', 1)->where('status', DocumentStatus::ReadyForReview->value)))
            ->whereHas('disciplinaryCase', fn ($case) => $case->where('company_id', $user->company_id))
            ->with(['disciplinaryCase.employee', 'disciplinaryCase.allegedResponsibleEmployee',
                'disciplinaryCase.witnesses', 'approvals'])->get();

        // Only a handful of documents await approval at a time, so each goes through the one approval rule
        // (forDocument) rather than a second copy of the mandate and eligibility logic.
        return $documents->filter(fn (Document $document): bool => $this->forDocument($document)
            ->contains(fn (array $row) => $row['user']->id === $user->id))->values();
    }

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
        $members = $mandate->poolMembers()->with('user.company')
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhere('branch_id', $case->branch_id))
            ->get();

        return $this->eligible($document, $members, $excluded);
    }

    private function eligible(Document $document, Collection $members, array $excluded): Collection
    {
        $approvals = $document->relationLoaded('approvals') ? $document->approvals : $document->approvals()->get();
        $current = $approvals->whereNull('invalidated_at');
        $approved = $current->filter(fn ($row) => $row->decision === ApprovalDecision::Approved)
            ->map(fn ($row) => $row->role_category->value)->all();
        $decided = $current->pluck('approver_user_id')->all();

        return $members->filter(fn ($member) => $member->user_id !== null && ! in_array($member->id, $excluded, true))
            ->filter(fn ($member) => $member->user?->is_active && $member->user->company?->is_active
                && ! in_array($member->role_type->value, $approved, true)
                && ! in_array($member->user_id, $decided, true))
            ->map(fn ($member) => ['member' => $member, 'user' => $member->user, 'category' => $member->role_type])
            ->values();
    }
}
