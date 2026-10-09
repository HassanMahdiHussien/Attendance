<?php

use App\Enums\AttachmentKind;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Livewire\Cases\ConfirmDocument;
use App\Livewire\Cases\ShowCase;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseAssignment;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\DefenseLetterFlow;
use App\Services\Cases\ResponseService;
use App\Services\Documents\DocumentCorrector;
use App\Services\Documents\DocumentDrafts;
use App\Services\Documents\DocumentIssuer;
use App\Services\Documents\SecondReview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

function runKCase(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'opened_by_user_id' => $hr->id]);

    return [$company, $hr, $case];
}

function runKSnapshot(TestCase $test, DisciplinaryCase $case): string
{
    $html = $test->get('/cases/'.$case->id)->assertOk()->getContent();

    return livewireSnapshotForComponent($html, ShowCase::class);
}

function runKUpdate(TestCase $test, string $snapshot, string $method, array $updates = []): TestResponse
{
    return $test->postJson(Livewire::getUpdateUri(), ['components' => [[
        'snapshot' => $snapshot, 'updates' => $updates,
        'calls' => [['path' => '', 'method' => $method, 'params' => []]],
    ]]], ['X-Livewire' => 'true']);
}

function runKOpen(DisciplinaryCase $case, User $hr): DisciplinaryCase
{
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case->update(['violation_place_freetext' => 'البوابة']);

    return app(CaseOpening::class)->open($case, $hr);
}

function runKIssued(): array
{
    [$company, $hr, $case] = runKCase();
    $case = runKOpen($case, $hr);
    $drafts = app(DocumentDrafts::class);
    $document = $drafts->createOrUpdate($case, DocumentType::DefenseLetter,
        ['ar' => 'يرجى تقديم دفاعك', 'en' => 'Please submit your defense'], $hr);
    $drafts->submit($document, $hr);
    $issued = app(DefenseLetterFlow::class)->confirmAndIssue($document, $hr);

    return [$company, $hr, $case->fresh(), $issued];
}

test('document draft service rejects reporter, committee and foreign HR even when called directly', function (): void {
    [$company, $hr, $case] = runKCase();
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $committee = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CommitteeMember]);
    $foreignCompany = Company::factory()->create();
    $foreign = User::factory()->create(['company_id' => $foreignCompany->id, 'primary_role' => UserRole::HrStaff]);
    foreach ([$reporter, $committee, $foreign] as $actor) {
        expect(fn () => app(DocumentDrafts::class)->createOrUpdate($case, DocumentType::DefenseLetter,
            ['ar' => 'نص', 'en' => 'Text'], $actor))->toThrow(AuthorizationException::class);
        expect(fn () => app(ResponseService::class)->markNoResponse($case, $actor))->toThrow(AuthorizationException::class);
    }
});

test('document edit and confirmation reject a document from another case', function (): void {
    [$company, $hr, $case] = runKCase();
    $other = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'opened_by_user_id' => $hr->id]);
    $document = Document::factory()->create(['company_id' => $company->id, 'case_id' => $other->id]);
    $this->actingAs($hr)->get("/cases/{$case->id}/documents/{$document->id}/edit")->assertNotFound();
    $this->actingAs($hr)->get("/cases/{$case->id}/documents/{$document->id}/confirm")->assertNotFound();
});

test('case page opening writes the status and opening events', function (): void {
    [$company, $hr, $case] = runKCase();
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case->update(['violation_place_freetext' => 'البوابة']);
    $this->actingAs($hr);
    Livewire::test(ShowCase::class, ['case' => (string) $case->id])->call('open')->assertHasNoErrors();
    expect($case->fresh()->status)->toBe(CaseStatus::Open);
    expect($case->events()->where('event_type', 'case_opened')->exists())->toBeTrue();
});

test('HR can clear only a name-only conflict with a reason and event', function (): void {
    [$company, $hr, $case] = runKCase();
    $mandate = CommitteeMandate::factory()->create(['effective_from' => now()->subDay()]);
    $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id,
        'person_name' => $case->employee->name, 'employee_id' => null]);
    $this->actingAs($hr);
    Livewire::test(ShowCase::class, ['case' => (string) $case->id])
        ->set('clearanceReason', 'تحققنا من هوية الشخص')->call('clearConflict', $member->id)->assertHasNoErrors();
    expect($case->events()->where('event_type', 'conflict_cleared')->exists())->toBeTrue();
});

test('case page assignments write an event and recheck visibility for every action', function (): void {
    [$company, $admin, $case] = runKCase();
    $case = runKOpen($case, $admin);
    $old = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $new = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $case->update(['assigned_hr_user_id' => $old->id]);
    $this->actingAs($old);
    $snapshot = runKSnapshot($this, $case);
    app(CaseAssignment::class)->assign($case, $new, $admin);
    runKUpdate($this, $snapshot, 'assign')->assertNotFound();
    expect($case->events()->where('event_type', 'case_assigned')->exists())->toBeTrue();
});

test('a stale case stage at the Livewire endpoint shows the reload message', function (): void {
    [$company, $hr, $case] = runKCase();
    $this->actingAs($hr);
    $snapshot = runKSnapshot($this, $case);
    runKOpen($case, $hr);
    $html = runKUpdate($this, $snapshot, 'open')->assertOk()->json('components.0.effects.html');
    expect($html)->toContain('The case changed. Reload the page.');
});

test('deactivated account is refused at the Livewire update endpoint', function (): void {
    [$company, $hr, $case] = runKCase();
    $this->actingAs($hr);
    $snapshot = runKSnapshot($this, $case);
    $hr->forceFill(['is_active' => false])->save();
    runKUpdate($this, $snapshot, 'assign')->assertUnauthorized();
});

test('an unchanged provisional reference leaves numbering to issuance', function (): void {
    [$company, $hr, $case] = runKCase();
    $case = runKOpen($case, $hr);
    $drafts = app(DocumentDrafts::class);
    $document = $drafts->createOrUpdate($case, DocumentType::DefenseLetter,
        ['ar' => 'يرجى تقديم دفاعك', 'en' => 'Please submit your defense'], $hr);
    $drafts->submit($document, $hr);
    $this->actingAs($hr);
    Livewire::test(ConfirmDocument::class, ['case' => (string) $case->id,
        'document' => (string) $document->id])->assertSet('referenceDirty', false)
        ->set('reference', 'MANUAL-1')->assertSet('referenceDirty', true);
});

test('citation failure stays in citation check and can return to edit', function (): void {
    [$company, $hr, $case] = runKCase();
    $case = runKOpen($case, $hr);
    $drafts = app(DocumentDrafts::class);
    $document = $drafts->createOrUpdate($case, DocumentType::DefenseLetter,
        ['ar' => 'المادة 12', 'en' => 'Article 12'], $hr);
    expect(fn () => $drafts->submit($document, $hr))->toThrow(TransitionBlocked::class);
    expect($case->fresh()->status)->toBe(CaseStatus::DefenseLetterCitationCheck);
    expect($case->events()->where('event_type', 'status_changed')->where('payload->to', 'defense_letter_citation_check')->exists())->toBeTrue();
    $drafts->backToEdit($document, $hr);
    expect($case->fresh()->status)->toBe(CaseStatus::Open);
});

test('editing after second review clears it and returns the first version to open', function (): void {
    [$company, $hr, $case] = runKCase();
    $company->update(['requires_second_review' => true]);
    $case = runKOpen($case, $hr);
    $reviewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $drafts = app(DocumentDrafts::class);
    $document = $drafts->createOrUpdate($case, DocumentType::DefenseLetter,
        ['ar' => 'طلب دفاع', 'en' => 'Defense request'], $hr);
    $drafts->submit($document, $hr);
    expect(fn () => app(SecondReview::class)->mark($document, $hr))->toThrow(IssuanceBlocked::class);
    app(SecondReview::class)->mark($document, $reviewer);
    expect($document->fresh()->second_reviewed_by)->toBe($reviewer->id);
    $drafts->createOrUpdate($case->fresh(), DocumentType::DefenseLetter,
        ['ar' => 'طلب دفاع مصحح', 'en' => 'Revised defense request'], $hr, $document);
    expect($document->fresh()->second_reviewed_by)->toBeNull();
    expect($case->fresh()->status)->toBe(CaseStatus::Open);
    expect($case->events()->where('event_type', 'document_draft_saved')->count())->toBe(2);
    $drafts->submit($document, $hr);
    expect(fn () => app(DocumentIssuer::class)->issue($document, $hr))->toThrow(IssuanceBlocked::class);
});

test('confirmation issues the defense letter and starts both deadlines', function (): void {
    [$company, $hr, $case, $issued] = runKIssued();
    expect($issued->is_locked)->toBeTrue();
    expect($issued->reference_number)->not->toBeNull();
    expect($case->status)->toBe(CaseStatus::AwaitingEmployeeResponse);
    expect($case->defense_letter_issued_at)->not->toBeNull();
    expect($case->events()->where('event_type', 'document_issued')->exists())->toBeTrue();
});

test('a correction preserves the issued original and the deadline anchor', function (): void {
    [$company, $hr, $case, $issued] = runKIssued();
    $anchor = $case->defense_letter_issued_at->toDateTimeString();
    $correction = app(DocumentCorrector::class)->startCorrection($issued, 'تصحيح التاريخ', $hr);
    expect($correction->version)->toBe(2);
    expect($issued->fresh()->is_locked)->toBeTrue();
    $drafts = app(DocumentDrafts::class);
    $drafts->createOrUpdate($case, DocumentType::DefenseLetter,
        ['ar' => 'يرجى تقديم دفاعك بعد تصحيح التاريخ', 'en' => 'Corrected date'], $hr, $correction);
    $drafts->submit($correction, $hr);
    app(DefenseLetterFlow::class)->confirmAndIssue($correction, $hr);
    expect($case->fresh()->defense_letter_issued_at->toDateTimeString())->toBe($anchor);
    expect($case->fresh()->status)->toBe(CaseStatus::AwaitingEmployeeResponse);
    expect($case->events()->where('event_type', 'document_correction_started')->exists())->toBeTrue();
    expect($case->events()->where('event_type', 'document_corrected_issued')->exists())->toBeTrue();
});

test('no response is an event after the due date without a state transition', function (): void {
    [$company, $hr, $case] = runKIssued();
    expect(fn () => app(ResponseService::class)->markNoResponse($case, $hr))->toThrow(TransitionBlocked::class);
    $this->travel(4)->days();
    app(ResponseService::class)->markNoResponse($case, $hr);
    expect($case->fresh()->status)->toBe(CaseStatus::AwaitingEmployeeResponse);
    expect($case->events()->where('event_type', 'response_not_received')->count())->toBe(1);
});

test('response scan stores an attachment and advances the case together', function (): void {
    [$company, $hr, $case] = runKIssued();
    $file = UploadedFile::fake()->createWithContent('response.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
    $attachment = app(ResponseService::class)->attachScan($case, $file, $hr);
    expect($attachment->kind)->toBe(AttachmentKind::EmployeeResponseScan);
    expect($case->fresh()->status)->toBe(CaseStatus::ResponseScannedUploaded);
    expect($case->events()->where('event_type', 'response_scan_uploaded')->exists())->toBeTrue();
});

test('case page requires two witnesses for refusal and records the response event', function (): void {
    [$company, $hr, $case] = runKIssued();
    $this->actingAs($hr);
    Livewire::test(ShowCase::class, ['case' => (string) $case->id])
        ->set('handoffPurpose', 'response_submission')->set('handoffStatus', 'refused_to_sign')
        ->set('witnessOne', 'شاهد أول')->call('recordHandOff')->assertHasErrors(['witnessTwo']);
    Livewire::test(ShowCase::class, ['case' => (string) $case->id])
        ->set('handoffPurpose', 'response_submission')->set('handoffStatus', 'refused_to_sign')
        ->set('witnessOne', 'شاهد أول')->set('witnessTwo', 'شاهد ثان')
        ->call('recordHandOff')->assertHasErrors(['witnessScan']);
    Livewire::test(ShowCase::class, ['case' => (string) $case->id])
        ->set('handoffPurpose', 'response_submission')->set('handoffStatus', 'refused_to_sign')
        ->set('witnessOne', 'شاهد أول')->set('witnessTwo', 'شاهد ثان')
        ->set('witnessScan', UploadedFile::fake()->createWithContent('witnesses.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF"))
        ->call('recordHandOff')->assertHasNoErrors();
    expect($case->fresh()->status)->toBe(CaseStatus::ResponseRefusedToSign);
    expect($case->events()->where('event_type', 'response_handoff_recorded')->exists())->toBeTrue();
});

test('defense receipt records a hand-off without advancing the response stage', function (): void {
    [$company, $hr, $case] = runKIssued();
    $this->actingAs($hr);
    Livewire::test(ShowCase::class, ['case' => (string) $case->id])
        ->set('handoffPurpose', 'defense_letter_receipt')->set('handoffStatus', 'signed')
        ->set('signedAt', now()->format('Y-m-d H:i:s'))->call('recordHandOff')->assertHasNoErrors();
    expect($case->fresh()->status)->toBe(CaseStatus::AwaitingEmployeeResponse);
    expect($case->events()->where('event_type', 'hand_off_recorded')->exists())->toBeTrue();
});

test('two unchanged provisional references resolve to distinct issued numbers', function (): void {
    [$company, $hr, $first] = runKCase();
    $second = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'opened_by_user_id' => $hr->id]);
    $drafts = app(DocumentDrafts::class);
    $documents = [];
    foreach ([$first, $second] as $case) {
        $case = runKOpen($case, $hr);
        $document = $drafts->createOrUpdate($case, DocumentType::DefenseLetter,
            ['ar' => 'يرجى تقديم دفاعك', 'en' => 'Please submit your defense'], $hr);
        $drafts->submit($document, $hr);
        $documents[] = $document;
    }
    $issuer = app(DocumentIssuer::class);
    $preview = $issuer->previewReference($documents[0]);
    expect($issuer->previewReference($documents[1]))->toBe($preview);
    $one = app(DefenseLetterFlow::class)->confirmAndIssue($documents[0], $hr);
    $two = app(DefenseLetterFlow::class)->confirmAndIssue($documents[1], $hr);
    expect($one->reference_number)->toBe($preview);
    expect($two->reference_number)->not->toBe($preview);
});
