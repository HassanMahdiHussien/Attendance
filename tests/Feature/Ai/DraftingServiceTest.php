<?php

use App\Ai\AiDrafting;
use App\Ai\AiUnavailable;
use App\Ai\CandidateUnits;
use App\Ai\ClaudeClient;
use App\Ai\DraftPrompt;
use App\Ai\InvalidAiOutput;
use App\Ai\PayloadBuilder;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Enums\DraftedBy;
use App\Jobs\DraftDocumentWithAi;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\LegalUnitTopic;
use App\Models\User;
use App\Services\Cases\CaseOpening;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClaudeClient;

function aiOpenCase(): array
{
    // The test queue is sync: without this, request() would run the job immediately and the tests could not
    // drive it (tokens, stale workers) themselves.
    Bus::fake([DraftDocumentWithAi::class]);
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin', 'name' => 'Hassan Mahdi']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id,
        'violation_place_freetext' => 'Warehouse', 'description' => 'Hassan Mahdi called 07701234567 and sent test@example.com.']);
    completeCompanySetup($company);
    $case = app(CaseOpening::class)->open($case, $hr);

    return compact('company', 'hr', 'law', 'case');
}

test('payload masks known names, phones, emails and long identifiers', function (): void {
    $f = aiOpenCase();
    $case = $f['case'];
    $case->update(['committee_session_notes' => 'Hassan Mahdi noted ID 1234567890123 and 07701234567.']);
    $builder = new PayloadBuilder;
    $payload = $builder->build($case->fresh(), DocumentType::Recommendations, collect());
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('Hassan Mahdi', '07701234567', 'test@example.com', '1234567890123');
    expect($json)->not->toContain('employee_code', 'national_id', 'salary', 'address');
});

test('candidate selection ranks discipline tags and honors the cap', function (): void {
    $f = aiOpenCase();
    $a = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'official_text' => 'Unrelated statute text']);
    $b = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'official_text' => 'Disciplinary statute text']);
    LegalUnitTopic::create(['legal_unit_id' => $b->id, 'topic' => 'discipline', 'created_by' => $f['hr']->id, 'created_at' => now()]);
    config()->set('displanery.ai.max_candidates', 1);
    expect(app(CandidateUnits::class)->for($f['case'], '')->pluck('id')->all())->toBe([$b->id]);
});

test('AI defense draft saves with origin and notes then clears its request token', function (): void {
    $f = aiOpenCase();
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'وقع الحادث وفق الوقائع المسجلة.'],
        'en' => ['body' => 'The incident follows the recorded facts.']], 'notes' => ['Review the description.']]);
    app()->instance(ClaudeClient::class, $fake);
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $token = $f['case']->fresh()->ai_request_id;
    app()->call([new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $token), 'handle']);
    $case = $f['case']->fresh();
    $document = $case->documents()->firstOrFail();
    expect($case->status)->toBe(CaseStatus::Open)
        ->and($case->ai_request_id)->toBeNull()
        ->and($document->drafted_by)->toBe(DraftedBy::Ai)
        ->and($document->aiDraftNotes()->count())->toBe(1);
    $note = ['key' => 'messages.ai.no_verified_citations', 'params' => []];
    expect($document->aiDraftNotes()->firstOrFail()->notes)->toContain($note);
    $previousLocale = app()->getLocale();
    try {
        $f['hr']->forceFill(['locale' => 'ar']);
        app()->setLocale($f['hr']->preferredLocale());
        expect(__($note['key'], $note['params']))->toBe('لا توجد مواد موثقة قابلة للاستشهاد');
        $f['hr']->forceFill(['locale' => 'en']);
        app()->setLocale($f['hr']->preferredLocale());
        expect(__($note['key'], $note['params']))->toBe('No verified legal provisions are available to cite.');
    } finally {
        app()->setLocale($previousLocale);
    }
    expect(fn () => DB::table('ai_draft_notes')->where('document_id', $document->id)
        ->update(['notes' => json_encode(['Altered'])]))->toThrow(QueryException::class);
});

test('a masked name the model translated is rejected instead of reaching the document', function (): void {
    $f = aiOpenCase();
    $fake = new FakeClaudeClient;
    // Arabic keeps the token; English renders it as "⟦Witness 1⟧", which nothing can restore.
    $fake->push(['languages' => ['ar' => ['body' => 'أوقف ⟦الموظف⟧ الآلة.'],
        'en' => ['body' => 'The machine was stopped by ⟦Witness 1⟧.']], 'notes' => []]);
    app()->instance(ClaudeClient::class, $fake);
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $token = $f['case']->fresh()->ai_request_id;
    expect(fn () => app()->call([new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $token), 'handle']))->toThrow(InvalidAiOutput::class);
    expect($f['case']->documents()->count())->toBe(0)
        ->and($fake->requests[0]['messages'][0]['content'] ?? json_encode($fake->requests[0]))->toContain('never translate or alter it');
});

test('stale worker saves nothing and final failure returns to the prior state', function (): void {
    $f = aiOpenCase();
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $token = $f['case']->fresh()->ai_request_id;
    $f['case']->fresh()->forceFill(['ai_request_id' => '67fa279f-3d32-4321-a2ab-dfc5b4262144'])->save();
    app()->call([new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $token), 'handle']);
    expect($f['case']->documents()->count())->toBe(0);
    $failed = new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, '67fa279f-3d32-4321-a2ab-dfc5b4262144');
    $failed->failed(new AiUnavailable('Unavailable'));
    expect($f['case']->fresh()->status)->toBe(CaseStatus::Open)
        ->and($f['case']->fresh()->ai_request_id)->toBeNull();
});

function aiSetCaseStage(DisciplinaryCase $case, string $status): DisciplinaryCase
{
    DB::statement('SET @displanery_workflow_transition = 1');
    try {
        DB::table('cases')->where('id', $case->id)->update(['status' => $status]);
    } finally {
        DB::statement('SET @displanery_workflow_transition = NULL');
    }

    return $case->fresh();
}

test('AI recommendations save both sections with the recorded outcome kept in the template', function (): void {
    $f = aiOpenCase();
    $f['case']->update(['committee_session_notes' => 'The committee discussed the incident.',
        'session_majority_reached' => true, 'outcome' => 'warning', 'defense_acceptance' => 'accepted_partially']);
    $case = aiSetCaseStage($f['case'], 'committee_session_held');
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => [
        'ar' => ['body' => 'ثبتت الوقائع الواردة في المحضر.', 'secondary_body' => 'تستند الأسباب إلى الوقائع المدونة.'],
        'en' => ['body' => 'The recorded facts were established.', 'secondary_body' => 'The reasons follow the recorded facts.'],
    ], 'notes' => []]);
    app()->instance(ClaudeClient::class, $fake);
    app(AiDrafting::class)->request($case, DocumentType::Recommendations, $f['hr']);
    app()->call([new DraftDocumentWithAi($case->id, $f['company']->id, $f['hr']->id,
        DocumentType::Recommendations, $case->fresh()->ai_request_id), 'handle']);
    $document = $case->documents()->where('document_type', DocumentType::Recommendations->value)->firstOrFail();
    expect($case->fresh()->status)->toBe(CaseStatus::CommitteeSessionHeld)
        ->and($document->drafted_by)->toBe(DraftedBy::Ai)
        ->and($document->contents()->whereNotNull('secondary_body')->count())->toBe(2);
});

test('AI decision reasons save after issued recommendations without changing the issued source', function (): void {
    $f = aiOpenCase();
    $case = $f['case'];
    $case->update(['outcome' => 'warning', 'defense_acceptance' => 'accepted_partially']);
    $source = Document::factory()->create(['company_id' => $f['company']->id, 'case_id' => $case->id,
        'document_type' => DocumentType::Recommendations, 'status' => 'draft', 'is_locked' => false]);
    foreach (['ar' => 'وقائع اللجنة', 'en' => 'Committee findings'] as $language => $body) {
        DB::table('document_contents')->insert(['company_id' => $f['company']->id, 'document_id' => $source->id,
            'language_code' => $language, 'body' => $body, 'secondary_body' => $body,
            'is_authoritative' => $language === 'ar', 'created_at' => now(), 'updated_at' => now()]);
    }
    $source->forceFill(['status' => 'issued', 'is_locked' => true])->save();
    $case = aiSetCaseStage($case, 'signed_recommendations_uploaded');
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'تستند الأسباب إلى التوصيات المعتمدة.'],
        'en' => ['body' => 'The reasons follow the approved recommendations.']], 'notes' => []]);
    app()->instance(ClaudeClient::class, $fake);
    app(AiDrafting::class)->request($case, DocumentType::DecisionLetter, $f['hr']);
    app()->call([new DraftDocumentWithAi($case->id, $f['company']->id, $f['hr']->id,
        DocumentType::DecisionLetter, $case->fresh()->ai_request_id), 'handle']);
    expect($case->fresh()->status)->toBe(CaseStatus::SignedRecommendationsUploaded)
        ->and($case->documents()->where('document_type', DocumentType::DecisionLetter->value)->firstOrFail()->drafted_by)->toBe(DraftedBy::Ai)
        ->and($source->fresh()->is_locked)->toBeTrue();
});

test('reconciler clears an expired token and a late worker cannot save', function (): void {
    $f = aiOpenCase();
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $token = $f['case']->fresh()->ai_request_id;
    DB::table('cases')->where('id', $f['case']->id)->update(['ai_request_started_at' => now()->subMinutes(21)]);
    Artisan::call('ai:reconcile-drafting');
    expect($f['case']->fresh()->status)->toBe(CaseStatus::Open)
        ->and($f['case']->fresh()->ai_request_id)->toBeNull();
    $fake = new FakeClaudeClient;
    app()->instance(ClaudeClient::class, $fake);
    app()->call([new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $token), 'handle']);
    expect($fake->requests)->toBe([])
        ->and($f['case']->documents()->count())->toBe(0);
});

test('Kurdish is requested only when enabled and a company template exists', function (): void {
    $f = aiOpenCase();
    $f['company']->update(['enabled_languages' => ['ar', 'en', 'ku']]);
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'وقعت مخالفة موثقة.'],
        'en' => ['body' => 'A documented incident occurred.']], 'notes' => []]);
    app()->instance(ClaudeClient::class, $fake);
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    app()->call([new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $f['case']->fresh()->ai_request_id), 'handle']);
    expect($fake->requests[0]['schema']['properties']['languages']['required'])->toBe(['ar', 'en']);

    DocumentTemplate::factory()->create(['company_id' => $f['company']->id,
        'document_type' => DocumentType::DefenseLetter->value, 'language_code' => 'ku']);
    $fake->push(['languages' => ['ar' => ['body' => 'وقعت مخالفة موثقة.'],
        'en' => ['body' => 'A documented incident occurred.'],
        'ku' => ['body' => 'ڕووداوێکی تۆمارکراو ڕوویدا.']], 'notes' => []]);
    app(AiDrafting::class)->request($f['case']->fresh(), DocumentType::DefenseLetter, $f['hr'], true);
    app()->call([new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $f['case']->fresh()->ai_request_id), 'handle']);
    expect($fake->requests[1]['schema']['properties']['languages']['required'])->toBe(['ar', 'en', 'ku'])
        ->and($f['case']->documents()->firstOrFail()->contents()->where('language_code', 'ku')->exists())->toBeTrue();
});

test('the payload carries the employee gender so Arabic wording agrees with it', function (): void {
    $f = aiOpenCase();
    $f['case']->employee->forceFill(['gender' => 'female'])->save();
    $payload = (new PayloadBuilder)->build($f['case']->fresh(), DocumentType::Recommendations, collect());
    $messages = app(DraftPrompt::class)->messages($payload, ['ar', 'en'], DocumentType::Recommendations);
    expect($payload['incident']['employee_gender'])->toBe('female')
        ->and(json_encode($messages, JSON_UNESCAPED_UNICODE))->toContain('employee_gender');
});

test('names are masked as whole words only', function (): void {
    $f = aiOpenCase();
    Employee::factory()->create(['name' => 'علي']);
    $f['case']->update(['description' => 'قال علي إن العطل وقع عليه أثناء العمل.']);
    $payload = (new PayloadBuilder)->build($f['case']->fresh(), DocumentType::DefenseLetter, collect());
    $description = $payload['incident']['description'];
    expect($description)->toContain('عليه')->not->toContain(' علي ');
});
