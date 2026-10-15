<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\Outcome;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CaseClosure
{
    public function __construct(private CaseWorkflow $workflow) {}

    public function closeWithoutPenalty(DisciplinaryCase $case, User $hr, ?string $reason = null): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $hr, $reason): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($case->incident_id !== null && blank($reason)) {
                throw ValidationException::withMessages(['incidentClosureReason' => __('messages.incident.closure_reason_required')]);
            }
            if (! $hr->is_active || ! $case->company->is_active
                || ! in_array($hr->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($hr)->whereKey($case->id)->exists()
                || $case->status !== CaseStatus::SignedRecommendationsUploaded
                || $case->outcome !== Outcome::RecommendationOnly
                || ($case->legal_review_requested_at && ! $case->legal_review_completed_at && ! $case->legal_review_cancelled_at)
                || ! $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->exists()) {
                throw new TransitionBlocked(__('messages.case.signed_recommendations_and_consultation_required'));
            }

            return $this->workflow->transition($case, CaseStatus::ArchivedFinal, $hr,
                $case->incident_id === null ? 'Closed without penalty' : 'Incident member closed: '.trim($reason));
        });
    }
}
