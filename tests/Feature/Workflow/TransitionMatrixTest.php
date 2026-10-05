<?php

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Exceptions\IllegalTransition;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseWorkflow;
use App\Support\Tenancy\TenantContext;
use App\Workflow\CaseTransitions;

test('every status has a mapped destination set and unmapped pairs are rejected', function (): void {
    expect(array_keys(CaseTransitions::MAP))->toEqualCanonicalizing(array_map(fn (CaseStatus $status) => $status->value, CaseStatus::cases()));
    foreach (CaseStatus::cases() as $from) {
        foreach (CaseStatus::cases() as $to) {
            expect(CaseTransitions::allows($from, $to))->toBe(in_array($to, CaseTransitions::MAP[$from->value], true));
        }
    }
    expect(CaseTransitions::MAP[CaseStatus::ArchivedFinal->value])->toBe([]);
});

test('each mapped edge can be exercised with its prerequisites while an illegal jump is refused', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
    $workflow = app(CaseWorkflow::class);

    foreach (CaseTransitions::MAP as $from => $targets) {
        foreach ($targets as $to) {
            $case = DisciplinaryCase::factory()->create([
                'status' => $from, 'law_version_id' => $law->id, 'opened_by_user_id' => $actor->id,
                'violation_place_freetext' => 'Warehouse', 'session_majority_reached' => true,
                'outcome' => 'recommendation_only', 'defense_letter_issued_at' => now()->subDays(40),
                'decision_issued_at' => now()->subDays(40),
                'legal_review_requested_at' => now()->subDays(2),
                'legal_review_completed_at' => now()->subDay(),
            ]);
            if ($from === CaseStatus::Open->value && $to === CaseStatus::DefenseLetterCitationCheck) {
                $case->documents()->create(['company_id' => $company->id, 'document_type' => DocumentType::DefenseLetter,
                    'version' => 1, 'drafted_by' => 'human']);
            }
            if ($to === CaseStatus::AwaitingExternalLegalConsultation) {
                $case->update(['legal_review_completed_at' => null]);
            }
            if ($to === CaseStatus::RecommendationsConfirmed) {
                $doc = $case->documents()->create(['company_id' => $company->id, 'document_type' => DocumentType::Recommendations,
                    'version' => 1, 'drafted_by' => 'human']);
                foreach (['legal', 'hr', 'labor_union'] as $role) {
                    $doc->approvals()->create(['company_id' => $company->id, 'approver_user_id' => $actor->id,
                        'role_category' => $role, 'decision' => 'approved', 'languages_present' => ['ar', 'en'], 'decided_at' => now()]);
                }
            }
            $citationTypes = [
                CaseStatus::DefenseLetterReadyForReview->value => DocumentType::DefenseLetter,
                CaseStatus::RecommendationsAwaitingApprovals->value => DocumentType::Recommendations,
                CaseStatus::DecisionLetterReadyForReview->value => DocumentType::DecisionLetter,
            ];
            if (isset($citationTypes[$to->value])) {
                Document::factory()->create(['case_id' => $case->id, 'document_type' => $citationTypes[$to->value]]);
            }
            $issuedTypes = [
                CaseStatus::DefenseLetterIssued->value => DocumentType::DefenseLetter,
                CaseStatus::RecommendationsPrinted->value => DocumentType::Recommendations,
                CaseStatus::DecisionIssued->value => DocumentType::DecisionLetter,
            ];
            if (isset($issuedTypes[$to->value])) {
                $case->documents()->create(['company_id' => $company->id, 'document_type' => $issuedTypes[$to->value],
                    'version' => 1, 'drafted_by' => 'human', 'status' => 'issued', 'is_locked' => true]);
            }
            $changed = $workflow->transition($case, $to, $actor, 'Matrix edge');
            expect($changed->status)->toBe($to);
            expect($case->events()->where('event_type', 'status_changed')->count())->toBe(1);
        }
    }

    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $actor->id]);
    expect(fn () => $workflow->transition($case, CaseStatus::CommitteeSessionHeld, $actor, 'Skip'))
        ->toThrow(IllegalTransition::class);
});
