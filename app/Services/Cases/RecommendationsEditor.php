<?php

namespace App\Services\Cases;

use App\Documents\CitationGate;
use App\Documents\DraftLanguages;
use App\Enums\CaseStatus;
use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Enums\DraftedBy;
use App\Enums\UserRole;
use App\Exceptions\TransitionBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\RegulationComparison;
use App\Models\User;
use App\Notifications\ApprovalNeeded;
use App\Services\CaseEventLogger;
use App\Services\Documents\CitationSync;
use App\Support\Access\CaseVisibility;
use App\Support\Text\ArabicText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class RecommendationsEditor
{
    private static bool $writing = false;

    public function __construct(private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public static function isWriting(): bool
    {
        return self::$writing;
    }

    public function create(DisciplinaryCase $case, array $contents, User $hrUser, DraftedBy $origin = DraftedBy::Human, ?int $comparisonId = null): Document
    {
        return DB::transaction(function () use ($case, $contents, $hrUser, $origin, $comparisonId): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($case->id);
            if ($case->status !== CaseStatus::CommitteeSessionHeld || ! $case->session_majority_reached
                || ! $hrUser->is_active || ! $case->company->is_active
                || ! in_array($hrUser->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($hrUser)->whereKey($case->id)->exists()
                || $case->documents()->where('document_type', DocumentType::Recommendations->value)->exists()) {
                throw new TransitionBlocked(__('messages.recommendations.hr_and_majority_required'));
            }
            $comparisonId ??= RegulationComparison::query()->where('case_id', $case->id)
                ->where('document_type', DocumentType::Recommendations->value)
                ->whereIn('status', ['confirmed', 'skipped'])->latest('id')->value('id');
            $document = Document::create(['company_id' => $case->company_id, 'case_id' => $case->id,
                'document_type' => DocumentType::Recommendations, 'drafted_by' => $origin,
                'status' => DocumentStatus::Draft, 'version' => 1, 'created_by' => $hrUser->id,
                'regulation_comparison_id' => $comparisonId]);
            if ($comparisonId) {
                $comparison = RegulationComparison::findOrFail($comparisonId);
                if ($comparison->consumed_at === null) {
                    $comparison->forceFill(['consumed_at' => now()])->save();
                }
            }
            $this->update($document, $contents, $hrUser, $origin);
            $this->events->log($case, 'recommendations_draft_created', ['document_id' => $document->id], actor: $hrUser);

            return $document->refresh();
        });
    }

    public function submitForApproval(Document $draft, User $hrUser): Document
    {
        return DB::transaction(function () use ($draft, $hrUser): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            $case = $document->disciplinaryCase;
            if ($document->document_type !== DocumentType::Recommendations || $document->is_locked
                || ($document->version === 1 && ! in_array($case->status, [CaseStatus::CommitteeSessionHeld, CaseStatus::RecommendationsCitationCheck], true))
                || ($document->version > 1 && in_array($case->status, [CaseStatus::Draft, CaseStatus::ArchivedFinal], true))
                || ! $hrUser->is_active || ! $case->company->is_active
                || ! in_array($hrUser->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($hrUser)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked(__('messages.recommendations.draft_cannot_submit'));
            }
            if ($document->approvals()->whereNull('invalidated_at')->where('decision', 'rejected')->exists()) {
                throw new TransitionBlocked(__('messages.recommendations.edit_after_rejection'));
            }
            foreach (app(DraftLanguages::class)->for($case->company, DocumentType::Recommendations) as $language) {
                $content = $document->contents()->where('language_code', $language)->first();
                if (blank($content?->body) || blank($content?->secondary_body)) {
                    throw new TransitionBlocked(__('messages.recommendations.body_required_in_language', ['language' => $language]));
                }
            }
            if ($document->version > 1) {
                app(CitationGate::class)->assertIssuable($document);
                $document->forceFill(['status' => DocumentStatus::ReadyForReview])->save();
                $this->events->log($case, 'recommendations_approval_requested', ['document_id' => $document->id], actor: $hrUser);
                Notification::send(app(PoolEligibility::class)->forDocument($document)->pluck('user')->unique('id'),
                    new ApprovalNeeded($document->id));

                return $document->refresh();
            }
            if ($case->status === CaseStatus::CommitteeSessionHeld) {
                $case = $this->workflow->transition($case, CaseStatus::RecommendationsCitationCheck, $hrUser, 'Recommendations submitted');
            }
            app(CitationGate::class)->assertIssuable($document);
            $document->forceFill(['status' => DocumentStatus::ReadyForReview])->save();
            $this->workflow->transition($case, CaseStatus::RecommendationsAwaitingApprovals, $hrUser, 'Recommendations citation check passed');
            $this->events->log($case, 'recommendations_approval_requested', ['document_id' => $document->id], actor: $hrUser);
            $document->unsetRelation('disciplinaryCase');
            $recipients = app(PoolEligibility::class)->forDocument($document)->pluck('user')->unique('id');
            Notification::send($recipients, new ApprovalNeeded($document->id));

            return $document->refresh();
        });
    }

    public function update(Document $draft, array $contents, User $hrUser, DraftedBy $origin = DraftedBy::Human, bool $syncCitations = true): Document
    {
        return DB::transaction(function () use ($draft, $contents, $hrUser, $origin, $syncCitations): Document {
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            $case = $document->disciplinaryCase;
            if ($document->document_type !== DocumentType::Recommendations || $document->is_locked
                || ($document->version === 1 && ! in_array($case->status, [CaseStatus::CommitteeSessionHeld, CaseStatus::RecommendationsCitationCheck,
                    CaseStatus::RecommendationsAwaitingApprovals, CaseStatus::RecommendationsConfirmed], true))
                || ($document->version > 1 && in_array($case->status, [CaseStatus::Draft, CaseStatus::ArchivedFinal], true))
                || ! $hrUser->is_active || ! $case->company->is_active
                || ! in_array($hrUser->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)
                || ! CaseVisibility::for($hrUser)->whereKey($case->id)->exists()) {
                throw new TransitionBlocked(__('messages.recommendations.hr_draft_permission_required'));
            }
            if (array_is_list($contents)) {
                $contents = collect($contents)->mapWithKeys(fn (array $item) => [$item['language_code'] => $item])->all();
            }
            if (count($contents) !== count(array_unique(array_keys($contents))) || in_array('', $contents, true)) {
                throw new TransitionBlocked(__('messages.recommendations.unique_languages_and_body_required'));
            }
            self::$writing = true;
            try {
                foreach ($contents as $language => $value) {
                    $body = is_array($value) ? ($value['body'] ?? null) : $value;
                    $secondary = is_array($value) ? ($value['secondary_body'] ?? null) : null;
                    if (blank($body)) {
                        throw new TransitionBlocked(__('messages.recommendations.findings_required_in_language', ['language' => $language]));
                    }
                    $body = ArabicText::westernDigits($body);
                    $secondary = $secondary === null ? null : ArabicText::westernDigits($secondary);
                    DocumentContent::updateOrCreate(
                        ['document_id' => $document->id, 'language_code' => $language],
                        ['company_id' => $document->company_id, 'body' => $body, 'secondary_body' => $secondary,
                            'is_authoritative' => $language === 'ar'],
                    );
                }
                $document->contents()->whereNotIn('language_code', array_keys($contents))->get()->each->delete();
            } finally {
                self::$writing = false;
            }
            $document->approvals()->whereNull('invalidated_at')->get()->each(function ($approval): void {
                $approval->invalidated_at = now();
                $approval->save();
            });
            $document->forceFill(['drafted_by' => $origin, 'last_edited_by' => $hrUser->id, 'second_reviewed_by' => null,
                'second_reviewed_at' => null, 'status' => DocumentStatus::Draft])->save();
            if ($syncCitations) {
                app(CitationSync::class)->sync($document);
            }
            $this->events->log($case, 'approvals_invalidated', ['document_id' => $document->id], actor: $hrUser);
            if (in_array($case->status, [CaseStatus::RecommendationsAwaitingApprovals, CaseStatus::RecommendationsConfirmed], true)) {
                $this->workflow->transition($case, CaseStatus::RecommendationsCitationCheck, $hrUser, 'Recommendations content edited');
            }

            return $document->refresh();
        });
    }
}
