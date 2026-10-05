<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus as S;
use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Services\Documents\DocumentIssuer;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CaseDocumentIssuance
{
    public function __construct(private DocumentIssuer $issuer, private CaseWorkflow $workflow, private CaseEventLogger $events) {}

    public function issue(Document $draft, User $actor, ?string $manualRef = null): Document
    {
        return DB::transaction(function () use ($draft, $actor, $manualRef): Document {
            $case = DisciplinaryCase::query()->lockForUpdate()->findOrFail($draft->case_id);
            Gate::forUser($actor)->authorize('issue', $case);
            $document = Document::query()->lockForUpdate()->findOrFail($draft->id);
            if (! CaseVisibility::for($actor)->whereKey($case->id)->exists()) {
                throw new IssuanceBlocked('Actor cannot access this case.');
            }
            $stages = [
                DocumentType::DefenseLetter->value => S::DefenseLetterConfirmed,
                DocumentType::Recommendations->value => S::RecommendationsConfirmed,
                DocumentType::DecisionLetter->value => S::DecisionLetterReadyForReview,
            ];
            if ($document->version === 1 && $case->status !== $stages[$document->document_type->value]) {
                throw new IssuanceBlocked('The case is not at this document issuance stage.');
            }
            if ($document->version > 1 && $case->status === S::Draft) {
                throw new IssuanceBlocked('A draft case cannot receive a correction.');
            }

            $issued = $this->issuer->issue($document, $actor, $manualRef);
            if ($document->version > 1) {
                $this->events->log($case, 'document_corrected_issued', [
                    'document_id' => $issued->id, 'reason' => $issued->correction_reason,
                ], actor: $actor);

                return $issued;
            }

            if ($document->document_type === DocumentType::DefenseLetter) {
                if ($case->defense_letter_issued_at === null) {
                    $case->defense_letter_issued_at = $issued->issued_at;
                    $case->save();
                }
                $case = $this->workflow->transition($case, S::DefenseLetterIssued, $actor, 'Defense letter issued');
                $this->workflow->transition($case, S::AwaitingEmployeeResponse, $actor, 'Response window opened');
            } elseif ($document->document_type === DocumentType::Recommendations) {
                $case = $this->workflow->transition($case, S::RecommendationsPrinted, $actor, 'Recommendations issued');
                $this->workflow->transition($case, S::AwaitingCommitteeSignatures, $actor, 'Awaiting paper signatures');
            } else {
                if ($case->decision_issued_at === null) {
                    $case->decision_issued_at = $issued->issued_at;
                    $case->save();
                }
                $case = $this->workflow->transition($case, S::DecisionIssued, $actor, 'Decision letter issued');
                $window = app(Deadlines::class);
                DB::table('appeal_trackings')->insertOrIgnore([
                    'company_id' => $case->company_id, 'case_id' => $case->id,
                    'window_start' => $window->appealEnd($case)->subDays((int) config('displanery.deadlines.appeal_days'))->toDateString(),
                    'window_end' => $window->appealEnd($case)->toDateString(),
                    'appeal_filed' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->workflow->transition($case, S::AwaitingAppealWindow, $actor, 'Appeal window opened');
            }

            return $issued;
        });
    }
}
