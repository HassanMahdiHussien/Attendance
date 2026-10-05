<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;

class ConsultationService
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function request(DisciplinaryCase $case, User $actor): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $actor): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if (in_array($case->status, [CaseStatus::Draft, CaseStatus::ArchivedFinal], true)
                || ! in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::CommitteeMember], true)
                || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()
                || $case->legal_review_requested_at !== null) {
                throw new TransitionBlocked('An eligible actor must request consultation once on an open case.');
            }
            $case->forceFill(['legal_review_requested_by' => $actor->id, 'legal_review_requested_at' => now(), 'is_sensitive' => true])->save();
            $this->events->log($case, 'legal_consultation_requested', [], actor: $actor);

            return $case;
        });
    }

    public function complete(DisciplinaryCase $case, User $reviewer, string $note): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $reviewer, $note): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($reviewer->primary_role !== UserRole::ExternalLegalReviewer || blank($note)
                || $case->legal_review_requested_at === null || $case->legal_review_completed_at !== null
                || ! CaseVisibility::for($reviewer)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked('A partnered external reviewer and pending visible consultation are required.');
            }
            $case->forceFill(['legal_review_completed_by' => $reviewer->id, 'legal_review_completed_at' => now(), 'legal_review_note' => $note])->save();
            $this->events->log($case, 'legal_consultation_completed', [], actor: $reviewer);
            if ($case->status === CaseStatus::AwaitingExternalLegalConsultation) {
                // The reviewer is authorized through the firm-wide visibility query.
                $case = $this->workflow->transition($case, CaseStatus::DecisionLetterReadyForReview, $reviewer,
                    'Consultation completed');
            }

            return $case;
        });
    }
}
