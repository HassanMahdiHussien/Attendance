<?php

use App\Enums\CaseStatus as S;
use App\Enums\DefenseAcceptance;
use App\Enums\Outcome;
use App\Enums\PoolRole;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseDocumentIssuance;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\Deadlines;
use App\Services\Cases\DocumentApprovalService;
use App\Services\Cases\ExpiredAppealArchiver;
use App\Services\Cases\HandOffService;
use App\Services\Cases\RecommendationsEditor;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;

function driveCaseToSignedRecommendations(Outcome $outcome): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id,
        'violation_place_freetext' => 'Warehouse']);
    $case = app(CaseOpening::class)->open($case, $hr);
    $workflow = app(CaseWorkflow::class);
    $defense = Document::factory()->create(['case_id' => $case->id, 'created_by' => $hr->id]);
    foreach (['ar', 'en'] as $language) {
        $defense->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'Human-written defense request', 'is_authoritative' => $language === 'ar']);
    }
    $case = $workflow->transition($case, S::DefenseLetterCitationCheck, $hr, 'Manual defense draft');
    $case = $workflow->transition($case, S::DefenseLetterReadyForReview, $hr, 'Citations checked', ['citation_check_passed' => true]);
    $case = $workflow->transition($case, S::DefenseLetterConfirmed, $hr, 'HR confirmed');
    app(CaseDocumentIssuance::class)->issue($defense, $hr);
    $case = $case->fresh();
    app(HandOffService::class)->record($case, SignaturePurpose::ResponseSubmission, SignatureStatus::Signed,
        ['signed_at' => now()], $hr);
    $case = $case->fresh();
    $mandate = CommitteeMandate::factory()->create(['effective_from' => '2026-01-01']);
    $attendees = [];
    $approvers = [];
    foreach ([PoolRole::Legal, PoolRole::Hr, PoolRole::LaborUnion] as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
        $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role, 'user_id' => $user->id]);
        $attendees[] = ['pool_member_id' => $member->id, 'vote' => 'approve'];
        $approvers[] = $user;
    }
    $case = app(CommitteeSessionService::class)->recordSession($case, $attendees, null, 'Human committee minutes',
        $outcome, DefenseAcceptance::Rejected, $hr);
    $recommendations = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations', 'created_by' => $hr->id]);
    app(RecommendationsEditor::class)->update($recommendations, ['ar' => 'Human recommendations', 'en' => 'Human recommendations'], $hr);
    $case = $workflow->transition($case, S::RecommendationsCitationCheck, $hr, 'Manual recommendations draft');
    $case = $workflow->transition($case, S::RecommendationsAwaitingApprovals, $hr, 'Citations checked', ['citation_check_passed' => true]);
    foreach ($approvers as $approver) {
        app(DocumentApprovalService::class)->approve($recommendations, $approver);
    }
    app(CaseDocumentIssuance::class)->issue($recommendations, $hr);
    $case = $workflow->transition($case->fresh(), S::SignedRecommendationsUploaded, $hr, 'Signed scan uploaded');

    return compact('company', 'hr', 'case', 'workflow');
}

test('manual warning path archives after the appeal window and records every status edge', function (): void {
    Carbon::setTestNow('2026-06-18 10:00:00');
    try {
        $f = driveCaseToSignedRecommendations(Outcome::Warning);
        $case = $f['case'];
        $hr = $f['hr'];
        $workflow = $f['workflow'];
        $case = $workflow->transition($case, S::DecisionLetterCitationCheck, $hr, 'Manual decision draft');
        $decision = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'decision_letter',
            'penalty_type' => 'warning', 'created_by' => $hr->id]);
        foreach (['ar', 'en'] as $language) {
            $decision->contents()->create(['company_id' => $f['company']->id, 'language_code' => $language,
                'body' => 'Human decision', 'is_authoritative' => $language === 'ar']);
        }
        $case = $workflow->transition($case, S::DecisionLetterReadyForReview, $hr, 'Citations checked', ['citation_check_passed' => true]);
        app(CaseDocumentIssuance::class)->issue($decision, $hr);
        expect(app(Deadlines::class)->responseDue($case->fresh())->toDateString())->toBe('2026-06-21');
        expect(app(Deadlines::class)->procedureDue($case->fresh())->toDateString())->toBe('2026-07-03');
        expect(app(Deadlines::class)->appealEnd($case->fresh())->toDateString())->toBe('2026-07-18');
        Carbon::setTestNow('2026-07-19 00:01:00');
        app(ExpiredAppealArchiver::class)->run();
        expect($case->fresh()->status)->toBe(S::ArchivedFinal);
        expect($case->events()->where('event_type', 'status_changed')->orderBy('id')->get()
            ->pluck('payload')->map(fn ($payload) => $payload['to'])->all())->toBe([
                'open', 'defense_letter_citation_check', 'defense_letter_ready_for_review', 'defense_letter_confirmed',
                'defense_letter_issued', 'awaiting_employee_response', 'response_received', 'committee_session_held',
                'recommendations_citation_check', 'recommendations_awaiting_approvals', 'recommendations_confirmed',
                'recommendations_printed', 'awaiting_committee_signatures', 'signed_recommendations_uploaded',
                'decision_letter_citation_check', 'decision_letter_ready_for_review', 'decision_issued',
                'awaiting_appeal_window', 'archived_final',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});

test('manual no-penalty path archives directly after signed recommendations', function (): void {
    Carbon::setTestNow('2026-06-18 10:00:00');
    try {
        $f = driveCaseToSignedRecommendations(Outcome::RecommendationOnly);
        $f['workflow']->transition($f['case'], S::ArchivedFinal, $f['hr'], 'No penalty');
        expect($f['case']->fresh()->status)->toBe(S::ArchivedFinal);
        expect($f['case']->fresh()->decision_issued_at)->toBeNull();
        expect($f['case']->events()->where('event_type', 'status_changed')->orderBy('id')->get()
            ->pluck('payload')->map(fn ($payload) => $payload['to'])->all())->toBe([
                'open', 'defense_letter_citation_check', 'defense_letter_ready_for_review', 'defense_letter_confirmed',
                'defense_letter_issued', 'awaiting_employee_response', 'response_received', 'committee_session_held',
                'recommendations_citation_check', 'recommendations_awaiting_approvals', 'recommendations_confirmed',
                'recommendations_printed', 'awaiting_committee_signatures', 'signed_recommendations_uploaded',
                'archived_final',
            ]);
    } finally {
        Carbon::setTestNow();
    }
});
