<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\Outcome;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Notifications\ConsultationReady;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ConsultationService
{
    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function request(DisciplinaryCase $case, User $actor): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $actor): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if (! $actor->is_active || ! $case->company->is_active
                || in_array($case->status, [CaseStatus::Draft, CaseStatus::ArchivedFinal], true)
                || ! in_array($actor->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::CommitteeMember], true)
                || ! CaseVisibility::for($actor)->whereKey($case->id)->exists()
                || $case->legal_review_requested_at !== null) {
                throw new TransitionBlocked('An eligible actor must request consultation once on an open case.');
            }
            $case->forceFill(['legal_review_requested_by' => $actor->id, 'legal_review_requested_at' => now(), 'is_sensitive' => true])->save();
            $this->events->log($case, 'legal_consultation_requested', [], actor: $actor);
            if (in_array($case->status, array_slice(CaseStatus::cases(),
                array_search(CaseStatus::SignedRecommendationsUploaded, CaseStatus::cases(), true)), true)) {
                $reviewers = User::query()->where('primary_role', UserRole::ExternalLegalReviewer->value)
                    ->where('is_active', true)->whereIn('law_firm_partner_id', DB::table('company_law_firm_partner')
                    ->where('company_id', $case->company_id)->select('law_firm_partner_id'))->get();
                Notification::send($reviewers, new ConsultationReady($case->id));
            }

            return $case;
        });
    }

    public function complete(DisciplinaryCase $case, User $reviewer, string $note): DisciplinaryCase
    {
        if (! CaseVisibility::for($reviewer)->whereKey($case->id)->exists()) {
            throw new TransitionBlocked('A partnered external reviewer and pending visible consultation are required.');
        }

        return app(TenantContext::class)->runForCompany($case->company, fn (): DisciplinaryCase => DB::transaction(function () use ($case, $reviewer, $note): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if (! $reviewer->is_active || ! $case->company->is_active
                || $reviewer->primary_role !== UserRole::ExternalLegalReviewer || blank($note)
                || $case->legal_review_requested_at === null || $case->legal_review_completed_at !== null
                || $case->legal_review_cancelled_at !== null || $case->status === CaseStatus::ArchivedFinal
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
        }));
    }

    public function cancel(DisciplinaryCase $case, string $reason, User $admin): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $reason, $admin): DisciplinaryCase {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if (! $admin->is_active || ! $case->company->is_active
                || $admin->primary_role !== UserRole::CompanyAdmin || blank($reason)
                || ! CaseVisibility::for($admin)->whereKey($case->id)->exists()
                || $case->status !== CaseStatus::SignedRecommendationsUploaded
                || $case->outcome !== Outcome::RecommendationOnly
                || $case->legal_review_requested_at === null
                || $case->legal_review_completed_at !== null || $case->legal_review_cancelled_at !== null) {
                throw new TransitionBlocked('An administrator and pending consultation are required.');
            }
            $case->forceFill(['legal_review_cancelled_at' => now(), 'legal_review_cancelled_by' => $admin->id,
                'legal_review_cancel_reason' => $reason])->save();
            $this->events->log($case, 'legal_consultation_cancelled', ['reason' => $reason], actor: $admin);
            app(AuditLogger::class)->log('legal_consultation_cancelled', $case, ['reason' => $reason], $admin);

            return $case;
        });
    }
}
