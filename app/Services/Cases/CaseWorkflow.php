<?php

namespace App\Services\Cases;

use App\Documents\CitationGate;
use App\Enums\CaseStatus as S;
use App\Enums\DocumentType;
use App\Exceptions\IllegalTransition;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use App\Workflow\CaseTransitions;
use Illuminate\Support\Facades\DB;

class CaseWorkflow
{
    private static bool $writingStatus = false;

    public function __construct(private CaseEventLogger $events) {}

    public static function isWritingStatus(): bool
    {
        return self::$writingStatus;
    }

    public function transition(DisciplinaryCase $case, S $to, ?User $actor, string $reason, array $payload = []): DisciplinaryCase
    {
        return DB::transaction(function () use ($case, $to, $actor, $reason, $payload): DisciplinaryCase {
            $locked = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($actor !== null && ! CaseVisibility::for($actor)->whereKey($locked->id)->exists()) {
                throw new TransitionBlocked('لا تملك صلاحية الوصول إلى هذه الحالة.');
            }
            if (! CaseTransitions::allows($locked->status, $to)) {
                throw new IllegalTransition("{$locked->status->value} cannot move to {$to->value}.");
            }
            $this->guard($locked, $to, $payload);

            self::$writingStatus = true;
            try {
                DB::statement('SET @displanery_workflow_transition = 1');
                $from = $locked->status;
                $locked->status = $to;
                if ($to === S::ArchivedFinal) {
                    $locked->closed_at ??= now();
                }
                $locked->save();
                $this->events->log($locked, 'status_changed', [
                    'from' => $from->value, 'to' => $to->value, 'reason' => $reason, 'payload' => $payload,
                ], actor: $actor);
            } finally {
                try {
                    DB::statement('SET @displanery_workflow_transition = NULL');
                } finally {
                    self::$writingStatus = false;
                }
            }

            return $locked;
        });
    }

    private function guard(DisciplinaryCase $case, S $to, array $payload): void
    {
        if ($to === S::Open) {
            foreach (['employee_id', 'branch_id', 'violation_type', 'violation_time'] as $field) {
                if (blank($case->{$field})) {
                    throw new TransitionBlocked("الحقل {$field} مطلوب.");
                }
            }
            if ($case->location_id === null && blank($case->violation_place_freetext)) {
                throw new TransitionBlocked('يلزم تحديد مكان المخالفة.');
            }
            if ($case->law_version_id === null) {
                throw new TransitionBlocked('يلزم تحديد قانون العمل المعمول به.');
            }
        }
        if ($case->status === S::Open && $to === S::DefenseLetterCitationCheck
            && ! $case->documents()->where('document_type', DocumentType::DefenseLetter->value)->where('is_locked', false)->exists()) {
            throw new TransitionBlocked('يلزم إعداد مسودة كتاب الدفاع.');
        }
        if (in_array($to, [S::DefenseLetterReadyForReview, S::RecommendationsAwaitingApprovals, S::DecisionLetterReadyForReview], true)) {
            $type = match ($to) {
                S::DefenseLetterReadyForReview => DocumentType::DefenseLetter,
                S::RecommendationsAwaitingApprovals => DocumentType::Recommendations,
                default => DocumentType::DecisionLetter,
            };
            $draft = $case->documents()->where('document_type', $type->value)
                ->whereIn('status', ['draft', 'ready_for_review'])->orderByDesc('version')->first();
            if (! $draft) {
                throw new TransitionBlocked('يلزم وجود مسودة حالية لفحص الاستشهادات.');
            }
            try {
                app(CitationGate::class)->assertIssuable($draft);
            } catch (IssuanceBlocked $error) {
                throw new TransitionBlocked($error->getMessage(), previous: $error);
            }
        }
        if (in_array($to, [S::AiDraftingRecommendations, S::RecommendationsCitationCheck], true)
            && $case->status === S::CommitteeSessionHeld && ! $case->session_majority_reached) {
            throw new TransitionBlocked('يلزم تحقق أغلبية اللجنة.');
        }
        if ($to === S::RecommendationsConfirmed && $case->documents()->where('document_type', DocumentType::Recommendations->value)
            ->whereIn('status', ['draft', 'ready_for_review'])->orderByDesc('version')->first()?->approvals()
            ->where('decision', 'approved')->whereNull('invalidated_at')->distinct()->count('role_category') !== 3) {
            throw new TransitionBlocked('يلزم اعتماد فئات الموافقة الثلاث.');
        }
        $issuanceTypes = [
            S::DefenseLetterIssued->value => DocumentType::DefenseLetter->value,
            S::RecommendationsPrinted->value => DocumentType::Recommendations->value,
            S::DecisionIssued->value => DocumentType::DecisionLetter->value,
        ];
        if (isset($issuanceTypes[$to->value]) && ! $case->documents()
            ->where('document_type', $issuanceTypes[$to->value])->where('version', 1)->where('status', 'issued')->exists()) {
            throw new TransitionBlocked('يلزم إصدار المستند المعني أولاً.');
        }
        if ($to === S::CommitteeSessionHeld && $case->status === S::AwaitingEmployeeResponse) {
            $due = app(Deadlines::class)->responseDue($case);
            if ($due === null || now('Asia/Baghdad')->startOfDay()->lte($due)) {
                throw new TransitionBlocked('لم تنقضِ مهلة الرد.');
            }
        }
        if ($to === S::AwaitingExternalLegalConsultation && ($case->legal_review_requested_at === null || $case->legal_review_completed_at !== null)) {
            throw new TransitionBlocked('لا توجد استشارة قيد الانتظار.');
        }
        if ($to === S::DecisionLetterReadyForReview && $case->legal_review_requested_at !== null && $case->legal_review_completed_at === null) {
            throw new TransitionBlocked('الاستشارة قيد الانتظار.');
        }
        if ($to === S::ArchivedFinal && in_array($case->status, [S::SignedRecommendationsUploaded, S::AiVerifyAndClose], true)
            && $case->outcome?->value !== 'recommendation_only') {
            throw new TransitionBlocked('لا يمكن الإغلاق هنا إلا بتوصية دون عقوبة.');
        }
        if ($to === S::ArchivedFinal && in_array($case->status, [S::SignedRecommendationsUploaded, S::AiVerifyAndClose], true)
            && $case->legal_review_requested_at !== null && $case->legal_review_completed_at === null
            && $case->legal_review_cancelled_at === null) {
            throw new TransitionBlocked('يلزم إكمال الاستشارة المطلوبة أو إلغاؤها قبل الإغلاق.');
        }
        if ($to === S::ArchivedFinal && $case->status === S::AwaitingAppealWindow) {
            $end = app(Deadlines::class)->appealEnd($case);
            if ($end === null || now('Asia/Baghdad')->toDateString() <= $end->toDateString()) {
                throw new TransitionBlocked('لم تنتهِ مهلة الطعن.');
            }
        }
    }
}
