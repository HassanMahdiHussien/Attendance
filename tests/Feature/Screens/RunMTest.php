<?php

use App\Documents\DocumentRenderer;
use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\DocumentType;
use App\Enums\Outcome;
use App\Enums\PoolRole;
use App\Enums\SignaturePurpose;
use App\Enums\SignatureStatus;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Livewire\Cases\EditDecisionLetter;
use App\Livewire\Cases\ReviewApproval;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Notifications\ConsultationReady;
use App\Services\Cases\AppealService;
use App\Services\Cases\CaseClosure;
use App\Services\Cases\CaseDocumentIssuance;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\ConsultationService;
use App\Services\Cases\DefenseLetterFlow;
use App\Services\Cases\DocumentApprovalService;
use App\Services\Cases\ExpiredAppealArchiver;
use App\Services\Cases\HandOffService;
use App\Services\Cases\RecommendationsEditor;
use App\Services\Cases\SignaturePresence;
use App\Services\Cases\TerminationLeaveConfirmation;
use App\Services\Documents\DocumentDrafts;
use App\Services\Documents\DocumentIssuer;
use App\Services\Documents\TemplateService;
use App\Support\Access\CaseVisibility;
use App\Support\Format\OutcomeLabel;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function runMThroughSignedRecommendations(Outcome $outcome, bool $consultation): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id, 'violation_place_freetext' => 'Warehouse']);
    $case = app(CaseOpening::class)->open($case, $hr);
    $drafts = app(DocumentDrafts::class);
    $defense = $drafts->createOrUpdate($case, DocumentType::DefenseLetter,
        ['ar' => 'طلب دفاع من الموارد البشرية', 'en' => 'Human written defense request'], $hr);
    $drafts->submit($defense, $hr);
    app(DefenseLetterFlow::class)->confirmAndIssue($defense, $hr);
    $case = $case->fresh();
    app(HandOffService::class)->record($case, SignaturePurpose::ResponseSubmission, SignatureStatus::Signed,
        ['signed_at' => now()], $hr);
    $case = $case->fresh();
    $mandate = CommitteeMandate::factory()->create(['effective_from' => '2026-01-01']);
    $members = [];
    $approvers = [];
    foreach (PoolRole::cases() as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
        $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role, 'user_id' => $user->id]);
        $members[] = $member;
        $approvers[$role->value] = $user;
    }
    $session = app(CommitteeSessionService::class);
    $votes = collect($members)->map(fn ($member) => ['pool_member_id' => $member->id, 'vote' => 'abstain'])->all();
    $session->recordSession($case, $votes, $members[0]->id, 'First vote', $outcome,
        DefenseAcceptance::AcceptedPartially, $hr, $members[1]->id);
    expect($case->fresh()->session_majority_reached)->toBeFalse();
    $votes = collect($members)->map(fn ($member) => ['pool_member_id' => $member->id, 'vote' => 'approve'])->all();
    $case = $session->recordSession($case->fresh(), $votes, $members[0]->id, 'Revised minutes', $outcome,
        DefenseAcceptance::AcceptedPartially, $hr, $members[1]->id);
    $reviewer = null;
    $unpartneredReviewer = null;
    if ($consultation) {
        $firm = LawFirmPartner::factory()->create();
        $otherFirm = LawFirmPartner::factory()->create();
        DB::table('company_law_firm_partner')->insert(['company_id' => $company->id, 'law_firm_partner_id' => $firm->id]);
        $reviewer = User::factory()->create(['primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $firm->id]);
        $unpartneredReviewer = User::factory()->create(['primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $otherFirm->id]);
        app(ConsultationService::class)->request($case, $hr);
        expect(CaseVisibility::for($reviewer)->whereKey($case->id)->exists())->toBeFalse();
    }
    $editor = app(RecommendationsEditor::class);
    $contents = ['ar' => ['body' => '**نتائج**\n- واقعة', 'secondary_body' => 'توصية اللجنة'],
        'en' => ['body' => '**Findings**\n- Fact', 'secondary_body' => 'Committee recommendation']];
    $recommendations = $editor->create($case, $contents, $hr);
    $editor->submitForApproval($recommendations, $hr);
    $approvals = app(DocumentApprovalService::class);
    $approvals->approve($recommendations, $approvers['legal']);
    $approvals->reject($recommendations, $approvers['hr'], 'Clarify finding');
    $contents['ar']['body'] = 'نتائج منقحة';
    $editor->update($recommendations, $contents, $hr);
    $editor->submitForApproval($recommendations, $hr);
    foreach ($approvers as $approver) {
        $approvals->approve($recommendations, $approver);
    }
    app(CaseDocumentIssuance::class)->issue($recommendations, $hr);
    $checks = CommitteeSessionAttendance::query()->where('case_id', $case->id)->pluck('id')->mapWithKeys(fn ($id) => [$id => 'filled'])->all();
    $first = array_key_first($checks);
    $checks[$first] = 'empty';
    $presence = app(SignaturePresence::class);
    $presence->recordSignedScan($recommendations, UploadedFile::fake()->image('signed.png'), $checks, $hr);
    expect($case->fresh()->status)->toBe(CaseStatus::AwaitingCommitteeSignatures);
    $checks[$first] = 'filled';
    $presence->recordSignedScan($recommendations, UploadedFile::fake()->image('rescan.png'), $checks, $hr);
    expect($case->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded);

    return compact('company', 'hr', 'case', 'recommendations', 'reviewer', 'unpartneredReviewer', 'approvers');
}

test('the service no-penalty path requires consultation completion or reasoned cancellation before archive', function (): void {
    Carbon::setTestNow('2026-06-18 10:00:00');
    try {
        Notification::fake();
        $f = runMThroughSignedRecommendations(Outcome::RecommendationOnly, true);
        Notification::assertSentTo($f['reviewer'], ConsultationReady::class);
        Notification::assertNotSentTo($f['unpartneredReviewer'], ConsultationReady::class);
        expect(fn () => app(CaseClosure::class)->closeWithoutPenalty($f['case'], $f['hr']))->toThrow(TransitionBlocked::class);
        app(ConsultationService::class)->cancel($f['case'], 'No penalty; consultation withdrawn', $f['hr']);
        app(CaseClosure::class)->closeWithoutPenalty($f['case'], $f['hr']);
        expect($f['case']->fresh()->status)->toBe(CaseStatus::ArchivedFinal);
        expect(fn () => app(ConsultationService::class)->complete($f['case'], $f['reviewer'], 'Late note'))
            ->toThrow(TransitionBlocked::class);
    } finally {
        Carbon::setTestNow();
    }
});

test('the service penalty path waits for consultation, records refused receipt and resolves an appeal', function (): void {
    Carbon::setTestNow('2026-06-18 10:00:00');
    try {
        $f = runMThroughSignedRecommendations(Outcome::Termination, true);
        $case = $f['case'];
        $hr = $f['hr'];
        Employee::factory()->create(['is_general_manager' => true]);
        $drafts = app(DocumentDrafts::class);
        $decision = $drafts->createOrUpdateDecision($case, ['ar' => 'أسباب القرار', 'en' => 'Decision reasons'], $hr);
        $drafts->submitDecision($decision, $hr);
        expect($case->fresh()->status)->toBe(CaseStatus::AwaitingExternalLegalConsultation);
        expect(fn () => app(CaseDocumentIssuance::class)->issue($decision, $hr))->toThrow(IssuanceBlocked::class);
        app(ConsultationService::class)->complete($case, $f['reviewer'], 'Reviewed the issued recommendations.');
        expect($case->fresh()->status)->toBe(CaseStatus::DecisionLetterReadyForReview);
        expect(fn () => app(CaseDocumentIssuance::class)->issue($decision, $hr))->toThrow(IssuanceBlocked::class);
        app(TerminationLeaveConfirmation::class)->confirm($decision, $hr);
        app(CaseDocumentIssuance::class)->issue($decision, $hr);
        $case = $case->fresh();
        app(SignaturePresence::class)->recordDecisionSignatures($decision, ['general_manager' => 'filled', 'union' => 'empty'], $hr);
        expect(fn () => app(HandOffService::class)->record($case, SignaturePurpose::DecisionLetterReceipt,
            SignatureStatus::DeliveryRefused, ['witness_1_name' => 'First witness', 'witness_2_name' => 'Second witness',
                'attempted_at' => now(), 'attempt_location' => 'Gate', 'attempted_by_name' => 'HR'], $hr))
            ->toThrow(TransitionBlocked::class);
        $receipt = app(HandOffService::class)->record($case, SignaturePurpose::DecisionLetterReceipt, SignatureStatus::DeliveryRefused,
            ['witness_1_name' => 'First witness', 'witness_2_name' => 'Second witness',
                'attempted_at' => now(), 'attempt_location' => 'Gate', 'attempted_by_name' => 'HR',
                'witness_scan' => UploadedFile::fake()->createWithContent('witnesses.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF")], $hr);
        expect($receipt->witnessScanAttachment->kind)->toBe(AttachmentKind::DeliveryProof);
        app(AppealService::class)->logFiled($case, $hr, '2026-06-20');
        app(AppealService::class)->logOutcome($case, $hr, 'Court decision recorded');
        expect($case->fresh()->status)->toBe(CaseStatus::ArchivedFinal);
    } finally {
        Carbon::setTestNow();
    }
});

test('a warning with no appeal archives through the scheduler after thirty calendar days', function (): void {
    Carbon::setTestNow('2026-06-18 10:00:00');
    try {
        $f = runMThroughSignedRecommendations(Outcome::Warning, false);
        Employee::factory()->create(['is_general_manager' => true]);
        $drafts = app(DocumentDrafts::class);
        $decision = $drafts->createOrUpdateDecision($f['case'], ['ar' => 'أسباب الإنذار', 'en' => 'Warning reasons'], $f['hr']);
        $drafts->submitDecision($decision, $f['hr']);
        app(CaseDocumentIssuance::class)->issue($decision, $f['hr']);
        expect($f['case']->fresh()->status)->toBe(CaseStatus::AwaitingAppealWindow);
        Carbon::setTestNow('2026-07-19 00:01:00');
        app(ExpiredAppealArchiver::class)->run();
        expect($f['case']->fresh()->status)->toBe(CaseStatus::ArchivedFinal);
    } finally {
        Carbon::setTestNow();
    }
});

test('decision drafting and hand-off reject every unrelated role and a foreign tenant', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::SignedRecommendationsUploaded, 'outcome' => Outcome::Warning]);
    $other = Company::factory()->create();
    $actors = [
        User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']),
        User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']),
        User::factory()->create(['company_id' => $other->id, 'primary_role' => 'hr_staff']),
    ];
    foreach ($actors as $actor) {
        expect(fn () => app(DocumentDrafts::class)->createOrUpdateDecision($case,
            ['ar' => 'أسباب', 'en' => 'Reasons'], $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(HandOffService::class)->record($case, SignaturePurpose::DecisionLetterReceipt,
            SignatureStatus::Signed, ['signed_at' => now()], $actor))->toThrow(AuthorizationException::class);
    }
    expect($case->documents()->count())->toBe(0);
});

test('a partnered reviewer opens a signed case and completes the note without a login tenant', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $firm = LawFirmPartner::factory()->create();
    $otherFirm = LawFirmPartner::factory()->create();
    DB::table('company_law_firm_partner')->insert(['company_id' => $company->id, 'law_firm_partner_id' => $firm->id]);
    $reviewer = User::factory()->create(['primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $firm->id]);
    $unpartnered = User::factory()->create(['primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $otherFirm->id]);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::SignedRecommendationsUploaded, 'legal_review_requested_at' => now()]);
    app(TenantContext::class)->clear();
    $this->actingAs($unpartnered)->get('/cases/'.$case->id.'/consultation')->assertNotFound();
    $this->actingAs($reviewer)->get('/cases/'.$case->id.'/consultation')->assertOk();
    expect(fn () => app(ConsultationService::class)->complete($case, $unpartnered, 'Wrong firm'))
        ->toThrow(TransitionBlocked::class);
    app(TenantContext::class)->setCompany($company);
    expect(fn () => app(ConsultationService::class)->complete($case, $admin, 'HR cannot complete'))
        ->toThrow(TransitionBlocked::class);
    app(TenantContext::class)->clear();
    app(ConsultationService::class)->complete($case, $reviewer, 'The firm reviewed the case.');
    expect(DisciplinaryCase::withoutGlobalScopes()->find($case->id)->legal_review_note)->toBe('The firm reviewed the case.');
});

test('consultation request admits attending members of each pool category and refuses unrelated roles', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $nonPool = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
    $firm = LawFirmPartner::factory()->create();
    $reviewer = User::factory()->create(['primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $firm->id]);
    $mandate = CommitteeMandate::factory()->create();
    $law = Law::factory()->create();
    $members = [];
    foreach (PoolRole::cases() as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
        $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role, 'user_id' => $user->id]);
        $members[] = [$member, $user];
    }
    foreach ([$admin, $hr, ...array_column($members, 1)] as $actor) {
        $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
            'assigned_hr_user_id' => $hr->id, 'law_version_id' => $law->id,
            'status' => CaseStatus::SignedRecommendationsUploaded]);
        foreach ($members as [$member]) {
            CommitteeSessionAttendance::factory()->create(['case_id' => $case->id,
                'committee_pool_member_id' => $member->id]);
        }
        foreach ([$reporter, $nonPool, $reviewer] as $forbidden) {
            expect(fn () => app(ConsultationService::class)->request($case, $forbidden))
                ->toThrow(TransitionBlocked::class);
        }
        app(ConsultationService::class)->request($case, $actor);
        expect($case->fresh()->legal_review_requested_by)->toBe($actor->id);
    }
});

test('pool members, reviewers and reporters cannot perform HR decision, receipt, closure or appeal actions', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $signed = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::SignedRecommendationsUploaded, 'outcome' => Outcome::Termination]);
    $noPenalty = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::SignedRecommendationsUploaded, 'outcome' => Outcome::RecommendationOnly,
        'legal_review_requested_at' => now()]);
    $appeal = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::AwaitingAppealWindow]);
    DB::table('appeal_trackings')->insert(['company_id' => $company->id, 'case_id' => $appeal->id,
        'window_start' => now()->toDateString(), 'window_end' => now()->addDays(30)->toDateString(),
        'appeal_filed' => false, 'created_at' => now(), 'updated_at' => now()]);
    $draft = Document::factory()->create(['case_id' => $signed->id, 'document_type' => 'decision_letter',
        'penalty_type' => 'termination', 'created_by' => $admin->id]);
    $issued = Document::factory()->create(['case_id' => $appeal->id, 'document_type' => 'decision_letter',
        'penalty_type' => 'warning', 'status' => 'issued', 'is_locked' => true, 'created_by' => $admin->id]);
    $firm = LawFirmPartner::factory()->create();
    $otherFirm = LawFirmPartner::factory()->create();
    DB::table('company_law_firm_partner')->insert(['company_id' => $company->id, 'law_firm_partner_id' => $firm->id]);
    $actors = [];
    $mandate = CommitteeMandate::factory()->create();
    foreach (PoolRole::cases() as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
        CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role, 'user_id' => $user->id]);
        $actors[] = $user;
    }
    $actors[] = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
    $actors[] = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $actors[] = User::factory()->create(['primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $firm->id]);
    $actors[] = User::factory()->create(['primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $otherFirm->id]);
    foreach ($actors as $actor) {
        expect(fn () => app(DocumentDrafts::class)->createOrUpdateDecision($signed,
            ['ar' => 'أسباب', 'en' => 'Reasons'], $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(DocumentDrafts::class)->submitDecision($draft, $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(TerminationLeaveConfirmation::class)->confirm($draft, $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(CaseDocumentIssuance::class)->issue($draft, $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(SignaturePresence::class)->recordDecisionSignatures($issued,
            ['general_manager' => 'filled', 'union' => 'filled'], $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(HandOffService::class)->record($appeal, SignaturePurpose::DecisionLetterReceipt,
            SignatureStatus::Signed, ['signed_at' => now()], $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(CaseClosure::class)->closeWithoutPenalty($noPenalty, $actor))->toThrow(TransitionBlocked::class);
        expect(fn () => app(ConsultationService::class)->cancel($noPenalty, 'Withdrawn', $actor))->toThrow(TransitionBlocked::class);
        expect(fn () => app(AppealService::class)->logFiled($appeal, $actor, now()->toDateString()))->toThrow(TransitionBlocked::class);
        expect(fn () => app(AppealService::class)->logOutcome($appeal, $actor, 'Outcome'))->toThrow(TransitionBlocked::class);
    }
    expect($signed->documents()->count())->toBe(1);
    expect($noPenalty->fresh()->legal_review_cancelled_at)->toBeNull();
    expect($appeal->fresh()->status)->toBe(CaseStatus::AwaitingAppealWindow);
});

test('female attendee labels and approved outcome wording render while old company versions fall back', function (): void {
    expect(OutcomeLabel::arabic('recommendation_only'))->toBe('توصية دون عقوبة');
    expect(OutcomeLabel::arabic('warning'))->toBe('إنذار');
    expect(OutcomeLabel::arabic('termination'))->toBe('إنهاء خدمة');
    expect(OutcomeLabel::arabic(null))->toBe('دون نتيجة بعد');
    $arabic = require app_path('Documents/Defaults/decision_letter.ar.php');
    $english = require app_path('Documents/Defaults/decision_letter.en.php');
    expect($arabic['defense_outcome_partial'])->toContain('الأخذ بجزء من الدفوع');
    expect($arabic['defense_outcome_accepted'])->toContain('الأخذ بالدفوع');
    expect($english['defense_outcome_partial'])->toContain('accept part of the arguments');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::CommitteeSessionHeld, 'defense_letter_issued_at' => now()->subDay(),
        'committee_session_held_at' => now(), 'session_majority_reached' => true, 'outcome' => 'warning']);
    $mandate = CommitteeMandate::factory()->create(['effective_from' => now()->subDays(3)]);
    $female = Employee::factory()->create(['gender' => 'female', 'name' => 'عضوة اللجنة']);
    foreach (PoolRole::cases() as $role) {
        $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role,
            'employee_id' => $role === PoolRole::Legal ? $female->id : null]);
        CommitteeSessionAttendance::factory()->create(['case_id' => $case->id,
            'committee_pool_member_id' => $member->id, 'is_chair' => $role === PoolRole::Legal]);
    }
    foreach (['ar', 'en'] as $language) {
        $blocks = require app_path("Documents/Defaults/recommendations.{$language}.php");
        unset($blocks['chair_label_female'], $blocks['member_label_female']);
        app(TemplateService::class)->saveVersion($company, 'recommendations', $language, $blocks,
            ['show_footer' => true, 'header_mode' => 'logo_text'], $admin);
    }
    $recommendations = app(RecommendationsEditor::class)->create($case, [
        'ar' => ['body' => 'نتائج', 'secondary_body' => 'توصية'],
        'en' => ['body' => 'Findings', 'secondary_body' => 'Recommendation'],
    ], $admin);
    $html = app(DocumentRenderer::class)->render($recommendations, true);
    expect($html)->toContain('رئيسةً', 'Chairwoman');
});

test('the new screens and archived cases enforce access at Livewire updates', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::SignedRecommendationsUploaded, 'outcome' => Outcome::Warning]);
    $this->actingAs($reporter)->get('/cases/'.$case->id.'/decision')->assertNotFound();
    $this->actingAs($reporter)->get('/cases/'.$case->id.'/consultation')->assertNotFound();
    $other = Company::factory()->create();
    $foreignHr = User::factory()->create(['company_id' => $other->id, 'primary_role' => 'hr_staff']);
    $appealCase = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::AwaitingAppealWindow]);
    $this->actingAs($foreignHr)->get('/cases/'.$case->id.'/decision')->assertNotFound();
    $this->actingAs($foreignHr)->get('/cases/'.$case->id.'/consultation')->assertNotFound();
    $this->actingAs($foreignHr)->get('/cases/'.$appealCase->id.'/appeal')->assertNotFound();
    $this->actingAs($admin);
    app(TenantContext::class)->setCompany($company);
    Livewire::test(EditDecisionLetter::class, ['case' => $case->id])
        ->set('reasons', ['ar' => 'أسباب', 'en' => 'Reasons']);
    $this->actingAs($reporter);
    Livewire::test(EditDecisionLetter::class, ['case' => $case->id])->assertNotFound();
    $archived = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::ArchivedFinal]);
    expect($admin->can('update', $archived))->toBeFalse();
    $oldDraft = Document::factory()->create(['case_id' => $archived->id, 'created_by' => $admin->id]);
    expect(fn () => app(DocumentIssuer::class)->issue($oldDraft, $admin))
        ->toThrow(IssuanceBlocked::class);
    $this->actingAs($admin)->get('/cases/'.$archived->id.'/appeal')->assertForbidden();
});

test('approval review formats every language and becomes read-only after that category decides', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'status' => CaseStatus::CommitteeSessionHeld, 'session_majority_reached' => true,
        'defense_letter_issued_at' => now()]);
    $mandate = CommitteeMandate::factory()->create(['effective_from' => now()->subDay()]);
    $approvers = [];
    foreach (PoolRole::cases() as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
        CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role, 'user_id' => $user->id]);
        $approvers[$role->value] = $user;
    }
    $document = app(RecommendationsEditor::class)->create($case, [
        'ar' => ['body' => '**النتائج**', 'secondary_body' => '- توصية'],
        'en' => ['body' => '**Findings**', 'secondary_body' => '- Recommendation'],
    ], $admin);
    app(RecommendationsEditor::class)->submitForApproval($document, $admin);
    $this->actingAs($approvers['legal']);
    Livewire::test(ReviewApproval::class, ['document' => $document->id])
        ->assertSee('العربية')->assertSee('English')->assertSeeHtml('<strong>Findings</strong>')
        ->call('approve')->assertRedirect('/committee');
    expect(session('committee_notice'))->toBe('سُجلت موافقتك');
    Livewire::test(ReviewApproval::class, ['document' => $document->id])
        ->assertSee('للقراءة فقط')->assertDontSee('رفض مع تعليق');
});

test('signature scan views describe appearance without claiming identity verification', function (): void {
    $views = collect(['signed-scan', 'show-case', 'edit-decision-letter'])
        ->map(fn ($name) => file_get_contents(resource_path("views/livewire/cases/{$name}.blade.php")))->implode(' ');
    expect($views)->not->toContain('هوية الموقّع موثقة', 'توقيع أصيل', 'signature verified');
    $session = file_get_contents(resource_path('views/livewire/cases/record-session.blade.php'));
    expect($session)->toContain('aria-label="{{ $member->person_name }}"', 'كتابة التوصيات');
});
