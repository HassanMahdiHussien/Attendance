<?php

namespace App\Services\Cases;

use App\Enums\ApprovalDecision;
use App\Enums\CaseStatus as S;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Access\CaseVisibility;
use App\Support\Format\Ui;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class NextAction
{
    public function __construct(private Deadlines $deadlines, private PoolEligibility $eligibility) {}

    public function all(DisciplinaryCase $case, User $viewer, ?Collection $eligibleDocumentIds = null,
        ?Collection $visibleReviewerIds = null): Collection
    {
        if ($case->status === S::ArchivedFinal) {
            return collect();
        }
        $base = '/cases/'.$case->id;
        $actions = collect();
        $documents = $case->relationLoaded('documents') ? $case->documents : $case->documents()->with('approvals')->get();
        foreach ($documents as $document) {
            if ($document->is_locked || ($document->status !== DocumentStatus::ReadyForReview
                && ! ($document->document_type === DocumentType::Recommendations && $document->version === 1
                    && $case->status === S::RecommendationsAwaitingApprovals))) {
                continue;
            }
            if ($document->document_type === DocumentType::Recommendations
                && ($case->status === S::RecommendationsAwaitingApprovals || $document->version > 1)) {
                $approvals = $document->relationLoaded('approvals') ? $document->approvals : $document->approvals()->get();
                $approved = $approvals->filter(fn ($approval) => $approval->invalidated_at === null
                    && $approval->decision === ApprovalDecision::Approved)->pluck('role_category')->unique()->count();
                if ($approved === 3) {
                    $actions->push($this->item('hr', 'review_issue', $base.'/documents/'.$document->id.'/confirm', $case, 2));

                    continue;
                }
                $eligible = $eligibleDocumentIds !== null ? $eligibleDocumentIds->contains($document->id)
                    : $this->eligibility->forDocument($document)->contains(fn (array $row) => $row['user']->id === $viewer->id);
                if ($eligible) {
                    $actions->push($this->item('committee', 'approve_recommendations', '/committee/approvals/'.$document->id, $case, 2));
                }
                $actions->push($this->item('waiting', 'waiting_approvals', $base, $case, 2, $approved));
            } elseif ($document->version > 1 || in_array($case->status, [S::DefenseLetterReadyForReview, S::DecisionLetterReadyForReview], true)) {
                $actions->push($this->item('hr', 'review_issue', $base.'/documents/'.$document->id.'/confirm', $case, 2));
            }
        }
        if ($case->legal_review_requested_at && ! $case->legal_review_completed_at && ! $case->legal_review_cancelled_at) {
            if ($viewer->primary_role === UserRole::ExternalLegalReviewer
                && ($visibleReviewerIds !== null ? $visibleReviewerIds->contains($case->id)
                    : CaseVisibility::for($viewer)->whereKey($case->id)->exists())) {
                $actions->push($this->item('reviewer', 'give_opinion', $base.'/consultation', $case, 3));
            }
            $actions->push($this->item('waiting', 'waiting_lawyer', $base, $case, 3));
        }
        if ($stage = $this->stage($case)) {
            $actions->push($stage);
            if ($case->status === S::AwaitingEmployeeResponse && $stage->actor === 'waiting') {
                $actions->push($this->item('optional', 'record_response', $base, $case));
            }
            if ($case->status === S::AwaitingAppealWindow && $stage->actor === 'waiting'
                && ($end = $this->deadlines->appealEnd($case)) && CarbonImmutable::now('Asia/Baghdad')->startOfDay()->lte($end)) {
                $actions->push($this->item('optional', 'record_appeal', $base.'/appeal', $case));
            }
        }

        return $actions;
    }

    public function for(DisciplinaryCase $case, User $viewer, ?Collection $eligibleDocumentIds = null,
        ?Collection $visibleReviewerIds = null): ?NextActionItem
    {
        $actions = $this->all($case, $viewer, $eligibleDocumentIds, $visibleReviewerIds);
        $actor = match ($viewer->primary_role) {
            UserRole::CompanyAdmin, UserRole::HrStaff => 'hr',
            UserRole::CommitteeMember => 'committee',
            UserRole::ExternalLegalReviewer => 'reviewer',
            default => null,
        };
        if ($actor === null) {
            return null;
        }
        $own = $actions->filter(fn (NextActionItem $action) => $action->actor === $actor
            || ($actor === 'hr' && $action->actor === 'committee'))->sortBy('priority')->first();
        if ($own) {
            return $own;
        }

        return $actor === 'hr' ? $actions->where('actor', 'waiting')->sortBy('priority')->first() : null;
    }

    public function forMany(Collection $cases, User $viewer): Collection
    {
        if (method_exists($cases, 'loadMissing')) {
            $cases->loadMissing(['documents.approvals', 'signatures']);
        } else {
            $cases->each(fn (DisciplinaryCase $case) => $case->loadMissing(['documents.approvals', 'signatures']));
        }
        $hasApprovals = $cases->contains(fn (DisciplinaryCase $case) => $case->documents->contains(
            fn ($document) => $document->document_type === DocumentType::Recommendations && ! $document->is_locked));
        $eligibleIds = $hasApprovals ? $this->eligibility->pendingFor($viewer)->pluck('id') : collect();
        $visibleReviewerIds = $viewer->primary_role === UserRole::ExternalLegalReviewer && $cases->isNotEmpty()
            ? CaseVisibility::for($viewer)->whereIn('cases.id', $cases->pluck('id'))->pluck('cases.id') : collect();

        return $cases->mapWithKeys(fn (DisciplinaryCase $case) => [$case->id => $this->for($case, $viewer, $eligibleIds, $visibleReviewerIds)]);
    }

    public function deadlineAlert(DisciplinaryCase $case, ?CarbonImmutable $now = null): ?string
    {
        $now ??= CarbonImmutable::now('Asia/Baghdad');
        if ($case->decision_issued_at === null && $case->status !== S::ArchivedFinal && ($due = $this->deadlines->procedureDue($case))) {
            if (in_array($this->deadlines->procedureStatus($case, $now), ['approaching', 'overdue'], true)) {
                return 'red';
            }
            if ($now->gte($due->endOfDay()->subDays(5))) {
                return 'amber';
            }
        }
        if ($case->status === S::AwaitingEmployeeResponse && ($due = $this->deadlines->responseDue($case))) {
            if ($now->startOfDay()->gt($due)) {
                return 'red';
            }
            if ($now->gte($due->endOfDay()->subDays(5))) {
                return 'amber';
            }
        }

        return null;
    }

    private function stage(DisciplinaryCase $case): ?NextActionItem
    {
        $base = '/cases/'.$case->id;
        $status = $case->status;
        if ($status === S::ArchivedFinal) {
            return null;
        }
        if ($status === S::AwaitingEmployeeResponse) {
            $received = $case->relationLoaded('signatures')
                ? $case->signatures->contains(fn ($signature) => $signature->purpose->value === 'defense_letter_receipt')
                : $case->signatures()->where('purpose', 'defense_letter_receipt')->exists();
            if (! $received) {
                return $this->item('hr', 'record_defense_receipt', $base, $case);
            }
            $due = $this->deadlines->responseDue($case);

            return $due && CarbonImmutable::now('Asia/Baghdad')->startOfDay()->gt($due)
                ? $this->item('hr', 'record_response_overdue', $base, $case)
                : $this->item('waiting', 'waiting_response', $base, $case);
        }
        if ($status === S::AwaitingAppealWindow) {
            $received = $case->relationLoaded('signatures')
                ? $case->signatures->contains(fn ($signature) => $signature->purpose->value === 'decision_letter_receipt')
                : $case->signatures()->where('purpose', 'decision_letter_receipt')->exists();

            return $received ? $this->item('waiting', 'appeal_window', $base.'/appeal', $case)
                : $this->item('hr', 'record_decision_receipt', $base, $case);
        }
        [$key, $url, $actor] = match ($status) {
            S::Draft => ['complete_case', $base, 'hr'],
            S::Open => ['prepare_defense', $base, 'hr'],
            S::AiDraftingDefenseLetter, S::AiDraftingRecommendations => ['ai_drafting', $base, 'hr'],
            S::AiVerifyAndClose => [$case->ai_request_document_type === DocumentType::DecisionLetter->value ? 'ai_drafting_decision' : 'ai_drafting', $base, 'hr'],
            S::DefenseLetterCitationCheck, S::RecommendationsCitationCheck, S::DecisionLetterCitationCheck => ['check_citations', $base, 'hr'],
            S::DefenseLetterReadyForReview, S::DecisionLetterReadyForReview => ['review_issue', $base, 'hr'],
            S::DefenseLetterConfirmed => ['issue_defense', $base, 'hr'],
            S::DefenseLetterIssued => ['response_window_opening', $base, 'waiting'],
            S::ResponseReceived, S::ResponseRefusedToSign, S::ResponseDeliveryRefused => ['upload_or_session', $base, 'hr'],
            S::ResponseScannedUploaded => ['read_response', $base, 'hr'],
            S::AiIngestedResponse => ['hold_session', $base.'/session', 'hr'],
            S::CommitteeSessionHeld => ['draft_recommendations', $base.'/recommendations', 'hr'],
            S::RecommendationsAwaitingApprovals => ['waiting_approvals', $base, 'waiting'],
            S::RecommendationsConfirmed => ['print_recommendations', $base, 'hr'],
            S::RecommendationsPrinted => ['signature_collection_opening', $base, 'waiting'],
            S::AwaitingCommitteeSignatures => ['upload_signed', $base.'/signed-scan', 'hr'],
            S::SignedRecommendationsUploaded => ['prepare_decision', $base, 'hr'],
            S::AwaitingExternalLegalConsultation => ['waiting_lawyer', $base, 'waiting'],
            S::DecisionIssued => ['appeal_window_opening', $base, 'waiting'],
            S::AppealPending => ['record_appeal_outcome', $base.'/appeal', 'hr'],
            S::AppealResolved => ['archiving_case', $base, 'waiting'],
            default => throw new \LogicException('Unmapped case status: '.$status->value),
        };

        return $this->item($actor, $key, $url, $case);
    }

    private function item(string $actor, string $key, string $url, DisciplinaryCase $case, int $priority = 4, ?int $approvalCount = null): NextActionItem
    {
        $due = $case->status === S::AwaitingEmployeeResponse ? $this->deadlines->responseDue($case)
            : ($case->status === S::AwaitingAppealWindow ? $this->deadlines->appealEnd($case) : $this->deadlines->procedureDue($case));

        $target = match ($key) {
            'record_defense_receipt', 'record_response_overdue', 'record_response', 'record_decision_receipt' => '#next-handoff',
            'upload_or_session', 'read_response' => '#next-response-scan',
            'record_appeal', 'record_appeal_outcome' => $url,
            'complete_case' => '/cases/new?draft='.$case->id,
            'prepare_decision' => in_array($case->outcome?->value, ['warning', 'termination'], true) ? $url.'/decision' : '#next-actions',
            'issue_defense' => $this->documentConfirmationTarget($case, DocumentType::DefenseLetter),
            'print_recommendations' => $this->documentConfirmationTarget($case, DocumentType::Recommendations),
            'check_citations' => $this->citationTarget($case),
            'waiting_response', 'waiting_approvals', 'waiting_lawyer', 'appeal_window',
            'response_window_opening', 'signature_collection_opening', 'appeal_window_opening', 'archiving_case' => null,
            'prepare_defense', 'ai_drafting', 'ai_drafting_decision', 'review_issue' => $url === '/cases/'.$case->id ? '#next-actions' : $url,
            default => $url,
        };

        $label = $key === 'waiting_approvals'
            ? __('messages.home.waiting_approvals_count', ['count' => $approvalCount ?? 0])
            : __('messages.home.'.$key, ['date' => Ui::date($due)]);

        return new NextActionItem($actor, $label, $url,
            $actor === 'waiting' ? 'gray' : 'indigo', $due,
            $this->deadlineAlert($case), $priority, $key, $target);
    }

    private function documentConfirmationTarget(DisciplinaryCase $case, DocumentType $type): string
    {
        $id = $case->relationLoaded('documents')
            ? $case->documents->where('document_type', $type)->sortByDesc('version')->first()?->id
            : $case->documents()->where('document_type', $type->value)->orderByDesc('version')->value('id');

        return $id ? '/cases/'.$case->id.'/documents/'.$id.'/confirm' : '#next-documents';
    }

    private function citationTarget(DisciplinaryCase $case): string
    {
        $type = match ($case->status) {
            S::DefenseLetterCitationCheck => DocumentType::DefenseLetter,
            S::RecommendationsCitationCheck => DocumentType::Recommendations,
            S::DecisionLetterCitationCheck => DocumentType::DecisionLetter,
            default => null,
        };
        if ($type === null) {
            return '#next-actions';
        }
        $id = $case->relationLoaded('documents')
            ? $case->documents->where('document_type', $type)->sortByDesc('version')->first()?->id
            : $case->documents()->where('document_type', $type->value)->orderByDesc('version')->value('id');
        if (! $id) {
            return '#next-actions';
        }

        return $type === DocumentType::Recommendations
            ? '/cases/'.$case->id.'/recommendations/'.$id
            : '/cases/'.$case->id.'/documents/'.$id.'/edit';
    }
}
