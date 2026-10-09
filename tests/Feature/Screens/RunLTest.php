<?php

use App\Enums\CaseStatus;
use App\Enums\DefenseAcceptance;
use App\Enums\DocumentType;
use App\Enums\Outcome;
use App\Enums\PoolRole;
use App\Enums\PresenceCheck;
use App\Exceptions\TransitionBlocked;
use App\Livewire\Cases\EditRecommendations;
use App\Livewire\Cases\ReviewApproval;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Notifications\ApprovalNeeded;
use App\Notifications\ApprovalRejected;
use App\Services\Cases\CaseClosure;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\CommitteeSessionService;
use App\Services\Cases\ConsultationService;
use App\Services\Cases\DocumentApprovalService;
use App\Services\Cases\PoolEligibility;
use App\Services\Cases\PresenceWording;
use App\Services\Cases\RecommendationsEditor;
use App\Services\Cases\SignaturePresence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

function runLFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id,
        'status' => CaseStatus::CommitteeSessionHeld, 'law_version_id' => $law->id,
        'opened_by_user_id' => $admin->id, 'defense_letter_issued_at' => '2026-06-18 09:00:00',
        'session_majority_reached' => true, 'outcome' => Outcome::RecommendationOnly]);
    $mandate = CommitteeMandate::factory()->create(['effective_from' => '2026-06-18']);
    $approvers = [];
    foreach (PoolRole::cases() as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
        CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role, 'user_id' => $user->id]);
        $approvers[$role->value] = $user;
    }

    return compact('company', 'admin', 'reporter', 'case', 'mandate', 'approvers');
}

function runLContents(string $text = 'First'): array
{
    return ['ar' => ['body' => $text.' findings', 'secondary_body' => $text.' recommendations'],
        'en' => ['body' => $text.' findings', 'secondary_body' => $text.' recommendations']];
}

test('recommendations rejection returns to editing and three fresh approvals are needed', function (): void {
    Notification::fake();
    $f = runLFixture();
    $editor = app(RecommendationsEditor::class);
    $document = $editor->create($f['case'], runLContents(), $f['admin']);
    expect(fn () => $editor->create($f['case'], runLContents(), $f['reporter']))->toThrow(TransitionBlocked::class);
    $editor->submitForApproval($document, $f['admin']);
    Notification::assertSentTo($f['approvers']['legal'], ApprovalNeeded::class);
    expect(app(PoolEligibility::class)->forDocument($document)->count())->toBe(3);
    $approvals = app(DocumentApprovalService::class);
    $approvals->approve($document, $f['approvers']['legal']);
    $approvals->reject($document, $f['approvers']['hr'], 'Revise findings');
    expect($f['case']->fresh()->status)->toBe(CaseStatus::RecommendationsCitationCheck);
    Notification::assertSentTo($f['admin'], ApprovalRejected::class);
    expect(fn () => $approvals->approve($document, $f['approvers']['labor_union']))->toThrow(TransitionBlocked::class);
    $editor->update($document, runLContents('Revised'), $f['admin']);
    expect($document->approvals()->whereNull('invalidated_at')->count())->toBe(0);
    $editor->submitForApproval($document, $f['admin']);
    Notification::assertSentToTimes($f['approvers']['legal'], ApprovalNeeded::class, 2);
    foreach (['legal', 'hr', 'labor_union'] as $role) {
        $approvals->approve($document, $f['approvers'][$role]);
    }
    expect($f['case']->fresh()->status)->toBe(CaseStatus::RecommendationsConfirmed);
});

test('session attribution must name an attendee and non HR roles cannot record it', function (): void {
    $f = runLFixture();
    $case = DisciplinaryCase::factory()->create(['company_id' => $f['company']->id,
        'status' => CaseStatus::ResponseReceived, 'law_version_id' => $f['case']->law_version_id,
        'opened_by_user_id' => $f['admin']->id, 'defense_letter_issued_at' => '2026-06-18 09:00:00']);
    $members = $f['mandate']->poolMembers->values();
    $attendees = $members->map(fn ($member) => ['pool_member_id' => $member->id, 'vote' => 'approve'])->all();
    $service = app(CommitteeSessionService::class);
    expect(fn () => $service->recordSession($case, $attendees, null, 'Minutes', Outcome::Warning,
        DefenseAcceptance::Rejected, $f['reporter'], $members[0]->id))->toThrow(AuthorizationException::class);
    expect(fn () => $service->recordSession($case, $attendees, null, 'Minutes', Outcome::Warning,
        DefenseAcceptance::Rejected, $f['admin'], 999999))->toThrow(TransitionBlocked::class);
    $service->recordSession($case, $attendees, null, 'Minutes', Outcome::Warning,
        DefenseAcceptance::Rejected, $f['admin'], $members[1]->id);
    expect($case->fresh()->minutes_typed_by_pool_member_id)->toBe($members[1]->id);
});

test('recommendations creation refuses committee, reporter and foreign HR service calls', function (): void {
    $f = runLFixture();
    $foreignCompany = Company::factory()->create();
    $foreignHr = User::factory()->create(['company_id' => $foreignCompany->id, 'primary_role' => 'hr_staff']);
    foreach ([$f['reporter'], $f['approvers']['legal'], $foreignHr] as $actor) {
        expect(fn () => app(RecommendationsEditor::class)->create($f['case'], runLContents(), $actor))
            ->toThrow(TransitionBlocked::class);
    }
    expect($f['case']->documents()->where('document_type', 'recommendations')->count())->toBe(0);
});

test('closure, cancellation and signature override refuse unauthorized actors', function (): void {
    $f = runLFixture();
    $document = Document::factory()->create(['case_id' => $f['case']->id,
        'document_type' => DocumentType::Recommendations]);
    expect(fn () => app(SignaturePresence::class)->overrideEmpty($document, 'Reason', $f['reporter']))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(CaseClosure::class)->closeWithoutPenalty($f['case'], $f['reporter']))
        ->toThrow(TransitionBlocked::class);
    expect(fn () => app(ConsultationService::class)->cancel($f['case'], 'Reason', $f['reporter']))
        ->toThrow(TransitionBlocked::class);
});

test('a new signed scan keeps empty boxes pending and a complete rescan advances the case', function (): void {
    $f = runLFixture();
    $case = DisciplinaryCase::factory()->create(['company_id' => $f['company']->id,
        'status' => CaseStatus::AwaitingCommitteeSignatures, 'opened_by_user_id' => $f['admin']->id,
        'law_version_id' => $f['case']->law_version_id,
        'defense_letter_issued_at' => '2026-06-18 09:00:00']);
    $document = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations',
        'status' => 'issued', 'is_locked' => true]);
    $attendees = [];
    foreach ($f['mandate']->poolMembers as $member) {
        $attendees[] = CommitteeSessionAttendance::create(['company_id' => $f['company']->id,
            'case_id' => $case->id, 'committee_pool_member_id' => $member->id,
            'is_chair' => count($attendees) === 0, 'vote' => 'approve', 'attended_at' => now()]);
    }
    $checks = collect($attendees)->mapWithKeys(fn ($attendance) => [$attendance->id => 'filled'])->all();
    $checks[$attendees[0]->id] = 'empty';
    $service = app(SignaturePresence::class);
    $service->recordSignedScan($document, UploadedFile::fake()->image('signed.png'), $checks, $f['admin']);
    expect($case->fresh()->status)->toBe(CaseStatus::AwaitingCommitteeSignatures);
    expect($service->latestChecks($document)->where('presence_check', PresenceCheck::BoxAppearsEmpty)->count())->toBe(1);
    $checks[$attendees[0]->id] = 'filled';
    $service->recordSignedScan($document, UploadedFile::fake()->image('rescan.png'), $checks, $f['admin']);
    expect($case->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded);
    expect($document->fresh()->is_locked)->toBeTrue();
});

test('an administrator can audit an empty box override but HR staff cannot', function (): void {
    $f = runLFixture();
    $case = DisciplinaryCase::factory()->create(['company_id' => $f['company']->id,
        'status' => CaseStatus::AwaitingCommitteeSignatures, 'law_version_id' => $f['case']->law_version_id,
        'opened_by_user_id' => $f['admin']->id]);
    $document = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations',
        'status' => 'issued', 'is_locked' => true]);
    $checks = [];
    foreach ($f['mandate']->poolMembers as $member) {
        $attendance = CommitteeSessionAttendance::create(['company_id' => $f['company']->id,
            'case_id' => $case->id, 'committee_pool_member_id' => $member->id,
            'is_chair' => ! $checks, 'vote' => 'approve', 'attended_at' => now()]);
        $checks[$attendance->id] = count($checks) === 0 ? 'empty' : 'filled';
    }
    $service = app(SignaturePresence::class);
    $service->recordSignedScan($document, UploadedFile::fake()->image('scan.png'), $checks, $f['admin']);
    $staff = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    expect(fn () => $service->overrideEmpty($document, 'Reason', $staff))->toThrow(AuthorizationException::class);
    $service->overrideEmpty($document, 'Paper copy reviewed', $f['admin']);
    expect($case->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded);
    expect($case->events()->where('event_type', 'empty_signature_box_overridden')->exists())->toBeTrue();
});

test('a requested consultation blocks no-penalty closure until an admin records cancellation', function (): void {
    $f = runLFixture();
    $case = DisciplinaryCase::factory()->create(['company_id' => $f['company']->id,
        'status' => CaseStatus::SignedRecommendationsUploaded, 'outcome' => Outcome::RecommendationOnly,
        'law_version_id' => $f['case']->law_version_id,
        'opened_by_user_id' => $f['admin']->id, 'legal_review_requested_at' => now(),
        'legal_review_requested_by' => $f['admin']->id]);
    Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations',
        'status' => 'issued', 'is_locked' => true]);
    $foreignCompany = Company::factory()->create();
    $foreignHr = User::factory()->create(['company_id' => $foreignCompany->id, 'primary_role' => 'hr_staff']);
    foreach ([$f['reporter'], $f['approvers']['legal'], $foreignHr] as $actor) {
        expect(fn () => app(CaseClosure::class)->closeWithoutPenalty($case, $actor))->toThrow(TransitionBlocked::class);
    }
    expect(fn () => app(CaseClosure::class)->closeWithoutPenalty($case, $f['admin']))->toThrow(TransitionBlocked::class);
    expect(fn () => app(CaseWorkflow::class)->transition($case, CaseStatus::ArchivedFinal, $f['admin'], 'Attempt'))
        ->toThrow(TransitionBlocked::class);
    expect(fn () => app(ConsultationService::class)->cancel($case, '', $f['admin']))->toThrow(TransitionBlocked::class);
    app(ConsultationService::class)->cancel($case, 'Not needed after committee review', $f['admin']);
    app(CaseClosure::class)->closeWithoutPenalty($case, $f['admin']);
    expect($case->fresh()->status)->toBe(CaseStatus::ArchivedFinal);
});

test('recommendations Livewire update reauthorizes and refuses a stale stage', function (): void {
    $f = runLFixture();
    $this->actingAs($f['admin']);
    $html = $this->get('/cases/'.$f['case']->id.'/recommendations')->assertOk()->getContent();
    $snapshot = livewireSnapshotForComponent($html, EditRecommendations::class);
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [],
        'calls' => [['path' => '', 'method' => 'save', 'params' => []]]]]];
    $this->actingAs($f['reporter'])->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertNotFound();
    $this->actingAs($f['admin']);
    app(CaseWorkflow::class)->transition($f['case'], CaseStatus::RecommendationsCitationCheck, $f['admin'], 'Stage changed');
    // The JSON payload escapes Unicode; assert on the decoded rendered HTML.
    $html = $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertOk()
        ->json('components.0.effects.html');
    expect($html)->toContain('The case changed');
});

test('approval Livewire update endpoint rejects a switched account', function (): void {
    Notification::fake();
    $f = runLFixture();
    $editor = app(RecommendationsEditor::class);
    $document = $editor->create($f['case'], runLContents(), $f['admin']);
    $editor->submitForApproval($document, $f['admin']);
    $this->actingAs($f['approvers']['legal']);
    $html = $this->get('/committee/approvals/'.$document->id)->assertOk()->getContent();
    $snapshot = livewireSnapshotForComponent($html, ReviewApproval::class);
    $this->actingAs($f['reporter'])->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => $snapshot, 'updates' => [],
        'calls' => [['path' => '', 'method' => 'approve', 'params' => []]],
    ]]], ['X-Livewire' => 'true'])->assertNotFound();
    expect($document->approvals()->count())->toBe(0);
});

test('another company cannot open the new case screens', function (): void {
    $f = runLFixture();
    $other = Company::factory()->create();
    $foreign = User::factory()->create(['company_id' => $other->id, 'primary_role' => 'company_admin']);
    $this->actingAs($foreign);
    $this->get('/cases/'.$f['case']->id.'/session')->assertNotFound();
    $this->get('/cases/'.$f['case']->id.'/recommendations')->assertNotFound();
    $this->get('/cases/'.$f['case']->id.'/signed-scan')->assertNotFound();
});

test('signature presence screens use appearance wording', function (): void {
    app()->setLocale('ar');
    $views = file_get_contents(resource_path('views/livewire/cases/signed-scan.blade.php'))
        .file_get_contents(resource_path('views/livewire/cases/show-case.blade.php'));
    // The labels come from PresenceWording (009); the views must use it and never claim verification.
    expect($views)->toContain('PresenceWording::')
        ->not->toContain('التوقيع موثق')->not->toContain('صحة التوقيع');
    expect(PresenceWording::choice('filled'))->toBe('خانة التوقيع تبدو ممتلئة');
    expect(PresenceWording::choice('empty'))->toBe('خانة التوقيع تبدو فارغة');
});
