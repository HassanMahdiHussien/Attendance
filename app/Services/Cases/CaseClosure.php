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

class CaseClosure
{
    public function __construct(private CaseWorkflow $workflow) {}

    public function closeWithoutPenalty(DisciplinaryCase $case, User $hr): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $hr): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if (! $hr->is_active || ! $case->company->is_active
                || ! in_array($hr->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($hr)->whereKey($case->id)->exists()
                || $case->status !== CaseStatus::SignedRecommendationsUploaded
                || $case->outcome !== Outcome::RecommendationOnly
                || ($case->legal_review_requested_at && ! $case->legal_review_completed_at && ! $case->legal_review_cancelled_at)
                || ! $case->documents()->where('document_type', DocumentType::Recommendations->value)->where('is_locked', true)->exists()) {
                throw new TransitionBlocked('يلزم توقيع التوصيات وإكمال الاستشارة لإغلاق الحالة دون عقوبة.');
            }

            return $this->workflow->transition($case, CaseStatus::ArchivedFinal, $hr, 'Closed without penalty');
        });
    }
}
