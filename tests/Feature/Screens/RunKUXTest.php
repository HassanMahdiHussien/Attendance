<?php

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\UserRole;
use App\Livewire\Cases\ConfirmDocument;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseBoard;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\DefenseLetterFlow;
use App\Services\Documents\DocumentDrafts;
use App\Support\Format\ArabicDigits;
use App\Support\Format\CaseTimeline;
use App\Support\Format\StageIndicator;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

function uxCompanyAndHr(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);

    return [$company, $hr];
}

function uxReadyDefenseLetter(Company $company, User $hr): array
{
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'opened_by_user_id' => $hr->id,
        'violation_place_freetext' => 'البوابة']);
    $case = app(CaseOpening::class)->open($case, $hr);
    $drafts = app(DocumentDrafts::class);
    $document = $drafts->createOrUpdate($case, DocumentType::DefenseLetter,
        ['ar' => 'يرجى تقديم دفاعك', 'en' => 'Please submit your defense'], $hr);
    $drafts->submit($document, $hr);

    return [$case, $document];
}

test('board offers five unique stages and stage four contains committee statuses only', function (): void {
    [$company, $hr] = uxCompanyAndHr();
    $law = Law::factory()->create();
    $committee = [];
    foreach ([CaseStatus::CommitteeSessionHeld, CaseStatus::RecommendationsAwaitingApprovals,
        CaseStatus::ResponseReceived, CaseStatus::DecisionIssued] as $status) {
        $case = DisciplinaryCase::factory()->create(['company_id' => $company->id,
            'opened_by_user_id' => $hr->id, 'law_version_id' => $law->id, 'status' => $status]);
        if (StageIndicator::step($status) === 4) {
            $committee[] = $case->id;
        }
    }
    $this->actingAs($hr);
    $html = $this->get('/cases')->assertOk()->getContent();
    preg_match('/<select[^>]*aria-label="المرحلة"[^>]*>(.*?)<\/select>/s', $html, $select);
    preg_match_all('/<option[^>]*>(.*?)<\/option>/s', $select[1], $options);
    expect($options[1])->toHaveCount(6);
    expect(array_unique($options[1]))->toHaveCount(6);
    expect(app(CaseBoard::class)->page($hr, stage: '4')->pluck('id')->all())->toEqualCanonicalizing($committee);
    expect(StageIndicator::statusesFor(4))->toContain(CaseStatus::CommitteeSessionHeld->value);
    expect(StageIndicator::statusesFor(4))->not->toContain(CaseStatus::ResponseReceived->value);
});

test('successful issuance redirects with a flash shown once on the case page', function (): void {
    [$company, $hr] = uxCompanyAndHr();
    [$case, $document] = uxReadyDefenseLetter($company, $hr);
    $this->actingAs($hr);
    Livewire::test(ConfirmDocument::class, ['case' => (string) $case->id, 'document' => (string) $document->id])
        ->call('issue')->assertRedirect('/cases/'.$case->id);
    $reference = $document->fresh()->reference_number;
    expect(session('case_success_'.$case->id))->toBe('صدر الكتاب بالعدد '.ArabicDigits::convert($reference));
    $this->get('/cases/'.$case->id)->assertOk()->assertSee('صدر الكتاب بالعدد '.ArabicDigits::convert($reference));
    $this->get('/cases/'.$case->id)->assertOk()->assertDontSee('صدر الكتاب بالعدد '.ArabicDigits::convert($reference));
});

test('a changed provisional number is explained after issuance', function (): void {
    [$company, $hr] = uxCompanyAndHr();
    [$firstCase, $firstDocument] = uxReadyDefenseLetter($company, $hr);
    [$secondCase, $secondDocument] = uxReadyDefenseLetter($company, $hr);
    $this->actingAs($hr);
    $secondScreen = Livewire::test(ConfirmDocument::class, ['case' => (string) $secondCase->id,
        'document' => (string) $secondDocument->id]);
    $preview = $secondScreen->get('reference');
    app(DefenseLetterFlow::class)->confirmAndIssue($firstDocument, $hr);
    $secondScreen->call('issue')->assertRedirect('/cases/'.$secondCase->id);
    expect($secondDocument->fresh()->reference_number)->not->toBe($preview);
    expect(session('case_success_'.$secondCase->id))->toContain('صدر الكتاب بالعدد '.ArabicDigits::convert($secondDocument->fresh()->reference_number));
    expect(session('case_success_'.$secondCase->id))->toContain('تغير الرقم المقترح أثناء العمل');
});

test('a repeated confirm action reports the existing issuance without issuing again', function (): void {
    [$company, $hr] = uxCompanyAndHr();
    [$case, $document] = uxReadyDefenseLetter($company, $hr);
    $this->actingAs($hr);
    $screen = Livewire::test(ConfirmDocument::class, ['case' => (string) $case->id,
        'document' => (string) $document->id]);
    app(DefenseLetterFlow::class)->confirmAndIssue($document, $hr);
    $count = $case->events()->where('event_type', 'document_issued')->count();
    $screen->call('issue')->assertRedirect('/cases/'.$case->id);
    expect($case->events()->where('event_type', 'document_issued')->count())->toBe($count);
    expect(session('case_success_'.$case->id))->toContain('صدر الكتاب بالعدد '.ArabicDigits::convert($document->fresh()->reference_number));
});

test('every recorded case event type has a specific Arabic timeline label', function (string $type): void {
    expect(CaseTimeline::format($type))->not->toStartWith('حدث غير مصنف');
})->with([
    'appeal_filed', 'appeal_outcome_recorded', 'approvals_invalidated', 'case_assigned',
    'case_draft_created', 'case_draft_updated', 'case_opened', 'committee_session_recorded',
    'conflict_cleared', 'deadline_alert_sent', 'document_corrected_issued',
    'document_correction_started', 'document_draft_saved', 'document_issued',
    'document_pdf_failed', 'document_second_reviewed', 'document_sent_back',
    'document_submitted_for_review', 'duplicate_report_linked', 'hand_off_recorded',
    'response_handoff_recorded', 'legal_consultation_completed', 'legal_consultation_requested',
    'recommendations_approved', 'recommendations_rejected',
    'response_not_received', 'response_scan_uploaded', 'status_changed',
    'termination_leave_confirmed',
]);

test('every case status has an Arabic label and the timeline names its target', function (): void {
    foreach (CaseStatus::cases() as $status) {
        expect(CaseTimeline::statusLabel($status))->not->toBe('حالة غير معروفة');
    }
    expect(CaseTimeline::format('status_changed', ['to' => CaseStatus::ResponseReceived->value]))
        ->toBe('انتقلت الحالة إلى: استلام رد الموظف');
    expect(CaseTimeline::format('document_issued', ['reference_number' => '1097']))
        ->toBe('صدر المستند بالعدد ١٠٩٧');
    expect(CaseTimeline::format('hand_off_recorded', ['purpose' => 'defense_letter_receipt',
        'status' => 'refused_to_sign'], ['witnesses' => ['أحمد', 'علي']]))
        ->toContain('كتاب طلب الدفاع');
    expect(CaseTimeline::format('hand_off_recorded', ['purpose' => 'defense_letter_receipt',
        'status' => 'refused_to_sign'], ['witnesses' => ['أحمد', 'علي']]))->toContain('أحمد، علي');
});

test('case page renders a status transition with its Arabic target', function (): void {
    [$company, $hr] = uxCompanyAndHr();
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'opened_by_user_id' => $hr->id]);
    app(CaseEventLogger::class)->log($case, 'status_changed', ['to' => CaseStatus::Open->value], actor: $hr);
    $this->actingAs($hr)->get('/cases/'.$case->id)->assertOk()->assertSee('انتقلت الحالة إلى: مفتوحة');
});
