<?php

namespace App\Services\Cases;

use App\Enums\ApprovalDecision;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\PoolRole;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\User;
use App\Notifications\ApprovalRejected;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DocumentApprovalService
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events, private PoolEligibility $eligibility) {}

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
                throw new TransitionBlocked(__('messages.approval.available_recommendations_required'));
            }
            if ($decision === ApprovalDecision::Rejected && blank($comment)) {
                throw new TransitionBlocked(__('messages.approval.rejection_comment_required'));
            }
            $eligible = $this->eligibility->forDocument($document)->filter(fn (array $row) => $row['user']->id === $user->id);
            if ($eligible->isEmpty()) {
                throw new TransitionBlocked(__('messages.approval.user_not_eligible_member'));
            }
            if ($category === null && $eligible->pluck('category')->unique()->count() !== 1) {
                throw new TransitionBlocked(__('messages.approval.category_required'));
            }
            $category ??= $eligible->first()['category'];
            $member = $eligible->first(fn (array $row) => $row['category'] === $category)['member'] ?? null;
            if ($member === null) {
                throw new TransitionBlocked(__('messages.approval.user_not_eligible_category'));
            }
            $valid = $document->approvals()->whereNull('invalidated_at');
            if ($valid->where('approver_user_id', $user->id)->exists()) {
                throw new TransitionBlocked(__('messages.approval.two_categories_forbidden'));
            }
            if ($decision === ApprovalDecision::Approved && $document->approvals()->whereNull('invalidated_at')
                ->where('role_category', $category->value)->where('decision', ApprovalDecision::Approved->value)->exists()) {
                throw new TransitionBlocked(__('messages.approval.category_already_approved'));
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
                $recipients = User::query()->where('company_id', $case->company_id)->where('is_active', true)
                    ->where(function ($query) use ($case): void {
                        $query->whereIn('id', array_filter([$case->opened_by_user_id, $case->assigned_hr_user_id]))
                            ->orWhere('primary_role', UserRole::CompanyAdmin->value);
                    })->get();
                Notification::send($recipients, new ApprovalRejected($case->id, $document->id));
            } elseif ($document->approvals()->whereNull('invalidated_at')->where('decision', ApprovalDecision::Approved->value)
                ->distinct()->count('role_category') === 3 && $document->version === 1) {
                $this->workflow->transition($case, CaseStatus::RecommendationsConfirmed, $user, 'Three categories approved');
            }

            return $approval;
        });
    }
}
