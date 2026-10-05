<?php

namespace App\Services\Cases;

use App\Enums\ApprovalDecision;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\PoolRole;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeMandate;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;

class DocumentApprovalService
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events, private ConflictClearance $conflicts) {}

    public function approve(Document $document, User $user, ?PoolRole $category = null): DocumentApproval
    {
        return $this->decide($document, $user, ApprovalDecision::Approved, null, $category);
    }

    public function reject(Document $document, User $user, string $comment, ?PoolRole $category = null): DocumentApproval
    {
        return $this->decide($document, $user, ApprovalDecision::Rejected, $comment, $category);
    }

    private function decide(Document $draft, User $user, ApprovalDecision $decision, ?string $comment, ?PoolRole $category): DocumentApproval
    {
        return DB::transaction(function () use ($draft, $user, $decision, $comment, $category): DocumentApproval {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            $case = $document->disciplinaryCase;
            if ($document->document_type !== DocumentType::Recommendations || $document->is_locked
                || ($document->version === 1 && $case->status !== CaseStatus::RecommendationsAwaitingApprovals)
                || ($document->version > 1 && $case->status === CaseStatus::Draft)
                || ! CaseVisibility::for($user)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked('An accessible recommendations draft awaiting approvals is required.');
            }
            if ($decision === ApprovalDecision::Rejected && blank($comment)) {
                throw new TransitionBlocked('A rejection comment is required.');
            }
            $mandate = CommitteeMandate::query()->where('company_id', $case->company_id)
                ->inEffectOn($case->defense_letter_issued_at)->orderByDesc('effective_from')->first();
            $members = $mandate?->poolMembers()->where('user_id', $user->id)
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $case->branch_id))->get();
            if ($members === null || $members->isEmpty()) {
                throw new TransitionBlocked('The user is not in the eligible committee pool.');
            }
            if ($category === null && $members->pluck('role_type')->unique()->count() !== 1) {
                throw new TransitionBlocked('Choose a role category for this approval.');
            }
            $category ??= $members->first()->role_type;
            $member = $members->first(fn ($row) => $row->role_type === $category);
            if ($member === null) {
                throw new TransitionBlocked('The user is not eligible for this category.');
            }
            if (collect($this->conflicts->conflicts($case))->contains(fn (array $row) => $row['member']->id === $member->id && $row['excluded'])) {
                throw new TransitionBlocked('A conflicted pool member cannot approve.');
            }
            $valid = $document->approvals()->whereNull('invalidated_at');
            if ($valid->where('approver_user_id', $user->id)->exists()) {
                throw new TransitionBlocked('One user cannot fill two categories.');
            }
            if ($decision === ApprovalDecision::Approved && $document->approvals()->whereNull('invalidated_at')
                ->where('role_category', $category->value)->where('decision', ApprovalDecision::Approved->value)->exists()) {
                throw new TransitionBlocked('This category is already approved.');
            }
            $approval = $document->approvals()->create([
                'company_id' => $document->company_id, 'committee_pool_member_id' => $member->id,
                'approver_user_id' => $user->id, 'role_category' => $category,
                'decision' => $decision, 'comment' => $comment,
                'languages_present' => $document->contents()->orderBy('language_code')->pluck('language_code')->all(),
                'decided_at' => now(),
            ]);
            $this->events->log($case, $decision === ApprovalDecision::Approved ? 'recommendations_approved' : 'recommendations_rejected',
                ['document_id' => $document->id, 'category' => $category->value, 'comment' => $comment], actor: $user);
            if ($decision === ApprovalDecision::Rejected && $document->version === 1) {
                $this->workflow->transition($case, CaseStatus::RecommendationsCitationCheck, $user, 'Recommendations rejected');
            } elseif ($document->approvals()->whereNull('invalidated_at')->where('decision', ApprovalDecision::Approved->value)
                ->distinct()->count('role_category') === 3 && $document->version === 1) {
                $this->workflow->transition($case, CaseStatus::RecommendationsConfirmed, $user, 'Three categories approved');
            }

            return $approval;
        });
    }
}
