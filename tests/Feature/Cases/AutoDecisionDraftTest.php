<?php

use App\Ai\AiDrafting;
use App\Ai\AiUnavailable;
use App\Ai\ClaudeClient;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Jobs\DraftDocumentWithAi;
use App\Jobs\SuggestSignaturePresence;
use App\Livewire\Cases\ShowCase;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\RegulationComparison;
use App\Models\User;
use App\Services\Cases\AutoDecisionDraft;
use App\Services\Cases\NextAction;
use App\Services\Cases\SignaturePresence;
use App\Services\Documents\DocumentIssuer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeClaudeClient;

function autoDecisionFixture(string $outcome = 'warning', bool $withComparison = false): array
{
    Bus::fake([DraftDocumentWithAi::class, SuggestSignaturePresence::class]);
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id,
        'status' => CaseStatus::AwaitingCommitteeSignatures, 'law_version_id' => $law->id,
        'outcome' => $outcome, 'defense_acceptance' => 'rejected']);
    completeCompanySetup($company);
    $document = Document::factory()->create(['case_id' => $case->id,
        'document_type' => DocumentType::Recommendations, 'status' => 'draft', 'is_locked' => false]);
    $comparison = null;
    if ($withComparison) {
        $comparison = RegulationComparison::create(['company_id' => $company->id, 'case_id' => $case->id,
            'document_type' => DocumentType::Recommendations->value, 'status' => 'confirmed',
            'origin' => 'manual', 'confirmed_by' => $hr->id, 'confirmed_at' => now()]);
        $document->forceFill(['regulation_comparison_id' => $comparison->id])->save();
    }
    foreach (['ar', 'en'] as $language) {
        DB::table('document_contents')->insert(['company_id' => $company->id, 'document_id' => $document->id,
            'language_code' => $language, 'body' => 'Committee findings', 'secondary_body' => 'Committee reasons',
            'is_authoritative' => $language === 'ar', 'created_at' => now(), 'updated_at' => now()]);
    }
    $document->forceFill(['status' => 'issued', 'is_locked' => true])->save();
    $attendees = collect(range(1, 3))->map(fn () => CommitteeSessionAttendance::factory()->create(['case_id' => $case->id]));
    $choices = $attendees->mapWithKeys(fn ($attendance) => [$attendance->id => 'filled'])->all();

    return compact('company', 'hr', 'case', 'document', 'attendees', 'choices', 'comparison');
}

test('filled boxes automatically request a penalty decision draft and the existing job saves it', function (string $outcome): void {
    $f = autoDecisionFixture($outcome);
    $presence = app(SignaturePresence::class);
    $scan = $presence->uploadSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['hr'], true);
    expect($f['case']->fresh()->ai_request_document_type)->toBe('signature_presence');
    $presence->recordPresence($f['document'], $scan, $f['choices'], $f['hr']);
    $case = $f['case']->fresh();
    expect($case->status)->toBe(CaseStatus::AiVerifyAndClose)
        ->and($case->ai_request_document_type)->toBe(DocumentType::DecisionLetter->value)
        ->and($case->events()->where('event_type', 'ai_draft_auto_requested')->count())->toBe(1)
        ->and(app(NextAction::class)->for($case, $f['hr'])->label)->toBe(__('messages.home.ai_drafting_decision'));
    Livewire::actingAs($f['hr'])->test(ShowCase::class, ['case' => (string) $case->id])
        ->assertSee(__('messages.home.ai_drafting_decision'));

    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'تستند الأسباب إلى التوصيات المعتمدة.'],
        'en' => ['body' => 'The reasons follow the approved recommendations.']], 'notes' => []]);
    app()->instance(ClaudeClient::class, $fake);
    app()->call([new DraftDocumentWithAi($case->id, $f['company']->id, $f['hr']->id,
        DocumentType::DecisionLetter, $case->ai_request_id), 'handle']);
    expect($case->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded)
        ->and($case->documents()->where('document_type', DocumentType::DecisionLetter->value)->exists())->toBeTrue();
})->with(['warning', 'termination']);

test('no penalty and an existing decision letter do not request an automatic draft', function (): void {
    foreach (['recommendation_only', 'warning'] as $outcome) {
        $f = autoDecisionFixture($outcome);
        if ($outcome === 'warning') {
            Document::factory()->create(['case_id' => $f['case']->id, 'document_type' => DocumentType::DecisionLetter]);
        }
        app(SignaturePresence::class)->recordSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['choices'], $f['hr']);
        expect($f['case']->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded)
            ->and($f['case']->events()->where('event_type', 'ai_draft_auto_requested')->count())->toBe(0);
    }
});

test('automatic request requires an issued recommendations document', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id,
        'status' => CaseStatus::SignedRecommendationsUploaded, 'law_version_id' => Law::factory()->create()->id,
        'outcome' => 'warning']);
    app(AutoDecisionDraft::class)->afterSignedRecommendations($case, $hr);
    expect($case->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded)
        ->and($case->fresh()->ai_request_id)->toBeNull();
});

test('an AI failure restores the ordinary decision step and an empty-box override also auto requests', function (): void {
    $f = autoDecisionFixture();
    $choices = $f['choices'];
    $choices[$f['attendees']->first()->id] = 'empty';
    app(SignaturePresence::class)->recordSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $choices, $f['hr']);
    expect($f['case']->fresh()->status)->toBe(CaseStatus::AwaitingCommitteeSignatures);
    app(SignaturePresence::class)->overrideEmpty($f['document'], 'Checked the paper copy', $f['hr']);
    $case = $f['case']->fresh();
    expect($case->status)->toBe(CaseStatus::AiVerifyAndClose);
    (new DraftDocumentWithAi($case->id, $f['company']->id, $f['hr']->id,
        DocumentType::DecisionLetter, $case->ai_request_id))->failed(new AiUnavailable('Unavailable'));
    expect($case->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded)
        ->and($case->fresh()->ai_request_id)->toBeNull()
        ->and(app(NextAction::class)->for($case->fresh(), $f['hr'])->key)->toBe('prepare_decision');
});

test('a non-presence request token prevents automatic drafting without losing the signed scan', function (): void {
    $f = autoDecisionFixture();
    $scan = app(SignaturePresence::class)->uploadSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['hr'], false);
    $f['case']->forceFill(['ai_request_id' => '67fa279f-3d32-4321-a2ab-dfc5b4262144',
        'ai_request_document_type' => 'regulation_comparison'])->save();
    app(SignaturePresence::class)->recordPresence($f['document'], $scan, $f['choices'], $f['hr']);
    expect($f['case']->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded)
        ->and($f['case']->events()->where('event_type', 'ai_draft_auto_requested')->count())->toBe(0);
});

test('request refusal is recorded and never rolls back the presence check', function (): void {
    $f = autoDecisionFixture();
    $drafting = Mockery::mock(AiDrafting::class);
    $drafting->shouldReceive('request')->once()->andThrow(new TransitionBlocked('Request refused'));
    app()->instance(AiDrafting::class, $drafting);
    app(SignaturePresence::class)->recordSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['choices'], $f['hr']);
    expect($f['case']->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded)
        ->and($f['case']->events()->where('event_type', 'ai_draft_auto_skipped')->firstOrFail()->payload['reason'])->toBe('request_blocked');
});

test('pending consultation permits automatic drafting while issuance still waits', function (): void {
    $f = autoDecisionFixture();
    $f['case']->forceFill(['legal_review_requested_at' => now(), 'legal_review_requested_by' => $f['hr']->id])->save();
    app(SignaturePresence::class)->recordSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['choices'], $f['hr']);
    expect($f['case']->fresh()->status)->toBe(CaseStatus::AiVerifyAndClose);
    $draft = Document::factory()->create(['case_id' => $f['case']->id, 'document_type' => DocumentType::DecisionLetter]);
    expect(fn () => app(DocumentIssuer::class)->issue($draft, $f['hr']))->toThrow(IssuanceBlocked::class,
        __('messages.document.consultation_completion_required'));
});

test('termination still needs the leave confirmation at issuance after automatic entry', function (): void {
    $f = autoDecisionFixture('termination');
    app(SignaturePresence::class)->recordSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['choices'], $f['hr']);
    expect($f['case']->fresh()->status)->toBe(CaseStatus::AiVerifyAndClose);
    $draft = Document::factory()->create(['case_id' => $f['case']->id, 'document_type' => DocumentType::DecisionLetter,
        'penalty_type' => 'termination']);
    expect(fn () => app(DocumentIssuer::class)->issue($draft, $f['hr']))->toThrow(IssuanceBlocked::class,
        __('messages.document.termination_leave_confirmation_required'));
});

test('automatic draft inherits the issued recommendations comparison', function (): void {
    $f = autoDecisionFixture(withComparison: true);
    app(SignaturePresence::class)->recordSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['choices'], $f['hr']);
    $case = $f['case']->fresh();
    expect($case->status)->toBe(CaseStatus::AiVerifyAndClose);
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'تستند الأسباب إلى التوصيات المعتمدة.'],
        'en' => ['body' => 'The approved reasons apply.']], 'notes' => []]);
    app()->instance(ClaudeClient::class, $fake);
    app()->call([new DraftDocumentWithAi($case->id, $f['company']->id, $f['hr']->id,
        DocumentType::DecisionLetter, $case->ai_request_id, $f['comparison']->id), 'handle']);
    expect($case->documents()->where('document_type', DocumentType::DecisionLetter->value)->firstOrFail()->regulation_comparison_id)
        ->toBe($f['comparison']->id);
});

test('a pending supplementary comparison on an existing decision draft stays untouched', function (): void {
    $f = autoDecisionFixture(withComparison: true);
    $draft = Document::factory()->create(['case_id' => $f['case']->id, 'document_type' => DocumentType::DecisionLetter,
        'regulation_comparison_id' => $f['comparison']->id]);
    $supplement = RegulationComparison::create(['company_id' => $f['company']->id, 'case_id' => $f['case']->id,
        'document_type' => DocumentType::DecisionLetter->value, 'status' => 'ready', 'origin' => 'manual']);
    $draft->forceFill(['supplementary_comparison_id' => $supplement->id])->save();
    app(SignaturePresence::class)->recordSignedScan($f['document'], UploadedFile::fake()->image('signed.png'), $f['choices'], $f['hr']);
    expect($f['case']->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded)
        ->and($draft->fresh()->supplementary_comparison_id)->toBe($supplement->id)
        ->and($supplement->fresh()->status)->toBe('ready');
    expect(fn () => app(AiDrafting::class)->request($f['case']->fresh(), DocumentType::DecisionLetter, $f['hr'], true))
        ->toThrow(TransitionBlocked::class, __('messages.regulation.confirm_first'));
});
