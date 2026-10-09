<?php

use App\Ai\AiDrafting;
use App\Ai\ClaudeClient;
use App\Ai\InvalidAiOutput;
use App\Ai\RegulationComparisons;
use App\Documents\CitationGate;
use App\Enums\DocumentType;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TenantMismatch;
use App\Exceptions\TransitionBlocked;
use App\Jobs\CheckCitationRelevance;
use App\Jobs\DraftDocumentWithAi;
use App\Jobs\SuggestRegulationComparison;
use App\Livewire\Cases\EditDocument;
use App\Models\AiGenerationLog;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\RegulationComparison;
use App\Models\User;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\RecommendationsEditor;
use App\Services\Documents\CitationOverride;
use App\Services\Documents\CitationSync;
use App\Services\Documents\DocumentCorrector;
use App\Services\Documents\DocumentDrafts;
use App\Services\Documents\RegulationExclusions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Fakes\FakeClaudeClient;

function regulationComparisonFixture(bool $approved = true): array
{
    Bus::fake([SuggestRegulationComparison::class, DraftDocumentWithAi::class, CheckCitationRelevance::class]);
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin', 'name' => 'Hassan Mahdi']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id,
        'description' => 'Hassan Mahdi reported 07701234567.', 'law_version_id' => $law->id,
        'violation_place_freetext' => 'Warehouse']);
    completeCompanySetup($company);
    $case = app(CaseOpening::class)->open($case, $hr);
    $lawUnit = LegalUnit::factory()->create(['law_id' => $law->id, 'official_text' => 'Official statute text']);
    $regulation = Law::factory()->create(['kind' => 'internal_regulation', 'company_id' => $company->id]);
    $regulationUnit = LegalUnit::factory()->create(['law_id' => $regulation->id,
        'status' => 'verified_text', 'official_text' => 'Official regulation text']);
    if ($approved) {
        DB::table('legal_unit_approvals')->insert(['company_id' => $company->id,
            'legal_unit_id' => $regulationUnit->id, 'approved_by' => $hr->id, 'created_at' => now()]);
    }

    return compact('company', 'hr', 'case', 'lawUnit', 'regulationUnit');
}

test('an unapproved regulation does not insert the comparison step', function (): void {
    $f = regulationComparisonFixture(false);
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    expect(RegulationComparison::count())->toBe(0)
        ->and($f['case']->fresh()->status->value)->toBe('ai_drafting_defense_letter');
});

test('approved regulation starts a single suggestion with a masked minimal payload', function (): void {
    $f = regulationComparisonFixture();
    $fake = new FakeClaudeClient;
    $fake->push(['pairs' => [['law_unit_id' => $f['lawUnit']->id,
        'regulation_unit_id' => $f['regulationUnit']->id, 'verdict' => 'conflict', 'reason' => 'The provisions differ.']]]);
    app()->instance(ClaudeClient::class, $fake);
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $comparison = RegulationComparison::firstOrFail();
    expect($f['case']->fresh()->status->value)->toBe('open')
        ->and($f['case']->fresh()->ai_request_document_type)->toBe('regulation_comparison')
        ->and($comparison->request_token)->toBe($f['case']->fresh()->ai_request_id);
    expect(fn () => app(AiDrafting::class)->request($f['case']->fresh(), DocumentType::DefenseLetter, $f['hr']))
        ->toThrow(TransitionBlocked::class);
    app()->call([new SuggestRegulationComparison($comparison->id, $f['case']->id, $f['company']->id,
        $f['hr']->id, $comparison->request_token), 'handle']);
    $message = $fake->requests[0]['messages'][0]['content'];
    expect($message)->not->toContain('Hassan Mahdi', '07701234567', 'employee_response', 'minutes', 'outcome')
        ->and($comparison->fresh()->status)->toBe('ready')
        ->and($f['case']->fresh()->ai_request_id)->toBeNull();
    expect(AiGenerationLog::query()->where('touchpoint', 'regulation_comparison')->count())->toBe(1);
    app(RegulationComparisons::class)->confirm($comparison->fresh(), $f['hr'], []);
    expect($comparison->fresh()->pairs()->firstOrFail()->verdict)->toBe('conflict')
        ->and($comparison->fresh()->confirmed_by)->toBe($f['hr']->id);
    expect(AuditLog::query()->where('action', 'regulation_comparison.confirmed')->count())->toBe(1);
    expect(fn () => DB::table('regulation_comparison_pairs')->where('comparison_id', $comparison->id)
        ->update(['verdict' => 'consistent']))->toThrow(QueryException::class);
});

test('conflict and skip exclude regulation citations through sync and issuance', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->addPair($comparison, $f['lawUnit']->id, $f['regulationUnit']->id, 'conflict', $f['hr']);
    app(RegulationComparisons::class)->confirm($comparison, $f['hr'], []);
    $document = Document::factory()->create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'regulation_comparison_id' => $comparison->id]);
    $document->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'ar',
        'body' => 'Claim [[cite:'.$f['regulationUnit']->id.']].', 'is_authoritative' => true]);
    expect(app(RegulationExclusions::class)->for($document))->toContain($f['regulationUnit']->id);
    expect(fn () => app(CitationSync::class)->sync($document))->toThrow(IssuanceBlocked::class);
    expect(fn () => app(CitationGate::class)->assertIssuable($document))->toThrow(IssuanceBlocked::class);
    $skipped = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'recommendations', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->skip($skipped, $f['hr'], 'Manual review unavailable');
    $document->forceFill(['regulation_comparison_id' => $skipped->id])->save();
    expect(app(RegulationExclusions::class)->for($document))->toContain($f['regulationUnit']->id);
    expect(fn () => DB::table('regulation_comparisons')->where('id', $skipped->id)
        ->update(['skip_reason' => 'Changed later']))->toThrow(QueryException::class);
});

test('late comparison worker cannot overwrite an expired token', function (): void {
    $f = regulationComparisonFixture();
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $comparison = RegulationComparison::firstOrFail();
    $f['case']->fresh()->forceFill(['ai_request_id' => '67fa279f-3d32-4321-a2ab-dfc5b4262144'])->save();
    $fake = new FakeClaudeClient;
    app()->instance(ClaudeClient::class, $fake);
    app()->call([new SuggestRegulationComparison($comparison->id, $f['case']->id, $f['company']->id,
        $f['hr']->id, $comparison->request_token), 'handle']);
    expect($fake->requests)->toBe([])->and($comparison->fresh()->status)->toBe('suggesting');
    DB::table('regulation_comparisons')->where('id', $comparison->id)->update(['created_at' => now()->subMinutes(21)]);
    Artisan::call('ai:reconcile-drafting');
    expect($comparison->fresh()->status)->toBe('failed');
});

test('suggestion cannot be skipped while its request token is active', function (): void {
    $f = regulationComparisonFixture();
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $comparison = RegulationComparison::firstOrFail();
    expect(fn () => app(RegulationComparisons::class)->skip($comparison, $f['hr'], 'AI is slow'))
        ->toThrow(TransitionBlocked::class);
});

test('comparison rows are tenant scoped', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']);
    $other = Company::factory()->create();
    app(TenantContext::class)->setCompany($other);
    expect(RegulationComparison::find($comparison->id))->toBeNull();
    expect(fn () => RegulationComparison::create(['company_id' => $other->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']))
        ->toThrow(TenantMismatch::class);
});

test('confirmed conflict is absent from draft candidates and the document retains the comparison', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->addPair($comparison, $f['lawUnit']->id, $f['regulationUnit']->id, 'conflict', $f['hr']);
    app(RegulationComparisons::class)->confirm($comparison, $f['hr'], []);
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'وقعت المخالفة وفق الوقائع المسجلة [[cite:'.$f['lawUnit']->id.']].'],
        'en' => ['body' => 'Claim [[cite:'.$f['lawUnit']->id.']].']], 'notes' => []]);
    app()->instance(ClaudeClient::class, $fake);
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $token = $f['case']->fresh()->ai_request_id;
    app()->call([new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $token, $comparison->id), 'handle']);
    $message = $fake->requests[0]['messages'][0]['content'];
    expect($message)->toContain('conflict', (string) $f['lawUnit']->id)
        ->not->toContain('Official regulation text')
        ->and($f['case']->documents()->firstOrFail()->regulation_comparison_id)->toBe($comparison->id)
        ->and($comparison->fresh()->consumed_at)->not->toBeNull();
});

test('invalid suggestion can fail into a manual comparison without losing the case', function (): void {
    $f = regulationComparisonFixture();
    $fake = new FakeClaudeClient;
    $fake->push(['pairs' => [['law_unit_id' => 999999, 'regulation_unit_id' => $f['regulationUnit']->id,
        'verdict' => 'conflict', 'reason' => 'Invalid law']]]);
    app()->instance(ClaudeClient::class, $fake);
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $comparison = RegulationComparison::firstOrFail();
    $job = new SuggestRegulationComparison($comparison->id, $f['case']->id, $f['company']->id,
        $f['hr']->id, $comparison->request_token);
    expect(fn () => app()->call([$job, 'handle']))->toThrow(InvalidAiOutput::class);
    $job->failed(new InvalidAiOutput('Invalid suggestion'));
    expect($comparison->fresh()->status)->toBe('failed')->and($f['case']->fresh()->ai_request_id)->toBeNull();
    app(RegulationComparisons::class)->addPair($comparison->fresh(), $f['lawUnit']->id,
        $f['regulationUnit']->id, 'more_favourable', $f['hr']);
    app(RegulationComparisons::class)->confirm($comparison->fresh(), $f['hr'], []);
    expect($comparison->fresh()->pairs()->firstOrFail()->changed_by_hr)->toBeTrue();
});

test('a decision letter inherits its issued recommendations comparison and needs coverage for a new regulation', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'recommendations', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->addPair($comparison, $f['lawUnit']->id, $f['regulationUnit']->id, 'consistent', $f['hr']);
    app(RegulationComparisons::class)->confirm($comparison, $f['hr'], []);
    $source = Document::factory()->create(['company_id' => $f['company']->id, 'case_id' => $f['case']->id,
        'document_type' => DocumentType::Recommendations, 'regulation_comparison_id' => $comparison->id]);
    $source->forceFill(['status' => 'issued', 'is_locked' => true])->save();
    $decision = Document::factory()->create(['company_id' => $f['company']->id, 'case_id' => $f['case']->id,
        'document_type' => DocumentType::DecisionLetter, 'regulation_comparison_id' => $comparison->id]);
    expect($decision->regulation_comparison_id)->toBe($source->regulation_comparison_id);
    $other = LegalUnit::factory()->create(['law_id' => $f['regulationUnit']->law_id,
        'status' => 'verified_text', 'official_text' => 'Another regulation unit']);
    DB::table('legal_unit_approvals')->insert(['company_id' => $f['company']->id,
        'legal_unit_id' => $other->id, 'approved_by' => $f['hr']->id, 'created_at' => now()]);
    $decision->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'ar',
        'body' => 'سبب القرار [[cite:'.$other->id.']].', 'is_authoritative' => true]);
    $claim = CitationSync::claims('سبب القرار [[cite:'.$other->id.']].')[0];
    $decision->citations()->create(['company_id' => $f['company']->id, 'legal_unit_id' => $other->id,
        'claim_sentence' => $claim['claim_sentence'], 'claim_hash' => $claim['claim_hash'],
        'relevance_check' => 'supports']);
    expect(fn () => app(CitationGate::class)->assertIssuable($decision))->toThrow(IssuanceBlocked::class);
    $supplement = app(RegulationComparisons::class)->startSupplementary($decision, $f['hr']);
    app(RegulationComparisons::class)->addPair($supplement, $f['lawUnit']->id, $other->id, 'consistent', $f['hr']);
    app(RegulationComparisons::class)->confirm($supplement, $f['hr'], []);
    expect($decision->fresh()->supplementary_comparison_id)->toBe($supplement->id);
    app(CitationGate::class)->assertIssuable($decision->fresh());
});

test('citation picker hides a conflicted regulation and rejects a forged selection', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->addPair($comparison, $f['lawUnit']->id, $f['regulationUnit']->id, 'conflict', $f['hr']);
    app(RegulationComparisons::class)->confirm($comparison, $f['hr'], []);
    $document = Document::factory()->create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => DocumentType::DefenseLetter,
        'regulation_comparison_id' => $comparison->id]);
    $this->actingAs($f['hr']);
    Livewire::test(EditDocument::class, ['case' => $f['case']->id, 'document' => (string) $document->id])
        ->call('openCitationPicker')->assertDontSee('picker-unit-'.$f['regulationUnit']->id)
        ->call('selectCitation', $f['regulationUnit']->id)
        ->assertSet('notice', __('messages.regulation.excluded_citation'));
});

test('manual defense and recommendations require confirmed non-conflict coverage for regulation citations', function (DocumentType $type, string $verdict): void {
    $f = regulationComparisonFixture();
    $document = Document::factory()->create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => $type, 'drafted_by' => 'human']);
    // Recommendations text may only be written through RecommendationsEditor (it resets approvals); this test is
    // about citation coverage, so it opens that editor's write window just for the fixture row.
    $writing = new ReflectionProperty(RecommendationsEditor::class, 'writing');
    $writing->setValue(null, true);
    try {
        $document->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'ar',
            'body' => 'Manual claim [[cite:'.$f['regulationUnit']->id.']].', 'is_authoritative' => true]);
    } finally {
        $writing->setValue(null, false);
    }

    expect(fn () => app(CitationSync::class)->sync($document))
        ->toThrow(IssuanceBlocked::class, __('messages.regulation.compare_before_citing'));
    expect(fn () => app(CitationGate::class)->assertIssuable($document))
        ->toThrow(IssuanceBlocked::class, __('messages.regulation.compare_before_citing'));

    $comparison = app(RegulationComparisons::class)->startForDocument($document, $f['hr']);
    app(RegulationComparisons::class)->addPair($comparison, $f['lawUnit']->id, $f['regulationUnit']->id,
        $verdict, $f['hr']);
    app(RegulationComparisons::class)->confirm($comparison, $f['hr'], []);
    $document->refresh();
    expect($document->regulation_comparison_id)->toBe($comparison->id);

    if ($verdict === 'conflict') {
        expect(fn () => app(CitationSync::class)->sync($document))
            ->toThrow(IssuanceBlocked::class, __('messages.regulation.excluded_citation'));
        expect(fn () => app(CitationGate::class)->assertIssuable($document))
            ->toThrow(IssuanceBlocked::class, __('messages.regulation.excluded_citation'));

        return;
    }

    app(CitationSync::class)->sync($document);
    $document->citations()->firstOrFail()->forceFill(['relevance_check' => 'supports'])->save();
    app(CitationGate::class)->assertIssuable($document);
})->with([
    'defense consistent' => [DocumentType::DefenseLetter, 'consistent'],
    'defense more favourable' => [DocumentType::DefenseLetter, 'more_favourable'],
    'defense conflict' => [DocumentType::DefenseLetter, 'conflict'],
    'recommendations consistent' => [DocumentType::Recommendations, 'consistent'],
    'recommendations more favourable' => [DocumentType::Recommendations, 'more_favourable'],
    'recommendations conflict' => [DocumentType::Recommendations, 'conflict'],
]);

test('HR can confirm a manual comparison in the defense editor before creating the draft', function (): void {
    $f = regulationComparisonFixture();
    $this->actingAs($f['hr']);
    Livewire::test(EditDocument::class, ['case' => (string) $f['case']->id, 'document' => 'new'])
        ->assertSee(__('messages.regulation.start_manual'))
        ->call('openCitationPicker')
        ->assertDontSee('picker-unit-'.$f['regulationUnit']->id)
        ->call('selectCitation', $f['regulationUnit']->id)
        ->assertSet('notice', __('messages.regulation.compare_before_citing'))
        ->call('closeCitationPicker')
        ->call('startManualComparison')
        ->set('comparisonLawId', $f['lawUnit']->id)
        ->set('comparisonRegulationId', $f['regulationUnit']->id)
        ->call('addComparisonPair')
        ->call('confirmComparison')
        ->call('openCitationPicker')
        ->assertSee('picker-unit-'.$f['regulationUnit']->id)
        ->set('bodies.ar', 'Manual claim [[cite:'.$f['regulationUnit']->id.']].')
        ->set('bodies.en', 'Manual claim.')
        ->call('save')
        ->assertHasNoErrors();

    $document = $f['case']->documents()->firstOrFail();
    expect($document->regulationComparison?->status)->toBe('confirmed')
        ->and($document->regulationComparison?->origin)->toBe('manual');
    app(CitationSync::class)->sync($document);
    expect($document->citations()->pluck('legal_unit_id')->all())->toContain($f['regulationUnit']->id);
});

test('a relevance override cannot restore an excluded regulation citation', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->addPair($comparison, $f['lawUnit']->id, $f['regulationUnit']->id, 'conflict', $f['hr']);
    app(RegulationComparisons::class)->confirm($comparison, $f['hr'], []);
    $document = Document::factory()->create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'regulation_comparison_id' => $comparison->id]);
    $citation = $document->citations()->create(['company_id' => $f['company']->id,
        'legal_unit_id' => $f['regulationUnit']->id, 'claim_sentence' => 'Claim',
        'claim_hash' => hash('sha256', 'Claim'), 'relevance_check' => 'does_not_support']);
    expect(fn () => app(CitationOverride::class)->override($citation, $f['hr'], 'Reviewed'))
        ->toThrow(ValidationException::class);
});

test('a correction keeps its issued version comparison', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->skip($comparison, $f['hr'], 'Law only');
    $issued = Document::factory()->create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'regulation_comparison_id' => $comparison->id]);
    $issued->forceFill(['status' => 'issued', 'is_locked' => true])->save();
    $correction = app(DocumentCorrector::class)->startCorrection($issued->fresh(), 'Correct wording', $f['hr']);
    expect($correction->regulation_comparison_id)->toBe($comparison->id)
        ->and(app(RegulationExclusions::class)->for($correction))->toContain($f['regulationUnit']->id);
});

test('reconciliation expires a stalled suggestion and preserves the manual path', function (): void {
    $f = regulationComparisonFixture();
    app(AiDrafting::class)->request($f['case'], DocumentType::DefenseLetter, $f['hr']);
    $comparison = RegulationComparison::firstOrFail();
    DB::table('cases')->where('id', $f['case']->id)->update(['ai_request_started_at' => now()->subMinutes(21)]);
    Artisan::call('ai:reconcile-drafting');
    expect($comparison->fresh()->status)->toBe('failed')
        ->and($f['case']->fresh()->ai_request_id)->toBeNull();
    app(RegulationComparisons::class)->skip($comparison->fresh(), $f['hr'], 'AI timed out');
    expect($comparison->fresh()->status)->toBe('skipped');
});

test('HR can change an AI conflict to worker favourable before it takes effect', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'ai']);
    $pair = $comparison->pairs()->create(['company_id' => $f['company']->id,
        'law_unit_id' => $f['lawUnit']->id, 'regulation_unit_id' => $f['regulationUnit']->id,
        'ai_verdict' => 'conflict', 'ai_reason' => 'Suggested conflict', 'verdict' => 'conflict']);
    app(RegulationComparisons::class)->confirm($comparison, $f['hr'], [$pair->id => 'more_favourable']);
    $document = Document::factory()->create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'regulation_comparison_id' => $comparison->id]);
    expect($pair->fresh()->verdict)->toBe('more_favourable')
        ->and($pair->fresh()->changed_by_hr)->toBeTrue()
        ->and(app(RegulationExclusions::class)->for($document))->toBe([]);
    $document->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'ar',
        'body' => 'حق العامل [[cite:'.$f['regulationUnit']->id.']].', 'is_authoritative' => true]);
    app(CitationSync::class)->sync($document);
    $document->citations()->firstOrFail()->forceFill(['relevance_check' => 'supports'])->save();
    app(CitationGate::class)->assertIssuable($document);
});

test('review note quotes both official units while document templates omit the comparison', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->addPair($comparison, $f['lawUnit']->id, $f['regulationUnit']->id, 'conflict', $f['hr']);
    app(RegulationComparisons::class)->confirm($comparison, $f['hr'], []);
    $document = Document::factory()->create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'regulation_comparison_id' => $comparison->id]);
    $note = view('livewire.cases.partials.regulation-comparison-note', compact('document'))->render();
    expect($note)->toContain('Official statute text', 'Official regulation text');
    foreach (File::allFiles(resource_path('views/documents')) as $template) {
        expect(file_get_contents($template->getPathname()))->not->toContain('regulation-comparison-note');
    }
    foreach (['confirm-document', 'review-approval', 'review-consultation'] as $screen) {
        expect(file_get_contents(resource_path('views/livewire/cases/'.$screen.'.blade.php')))
            ->toContain("@include('livewire.cases.partials.regulation-comparison-note')");
    }
});

test('manual drafting after a skipped comparison retains law-only exclusions', function (): void {
    $f = regulationComparisonFixture();
    $comparison = RegulationComparison::create(['company_id' => $f['company']->id,
        'case_id' => $f['case']->id, 'document_type' => 'defense_letter', 'status' => 'ready', 'origin' => 'manual']);
    app(RegulationComparisons::class)->skip($comparison, $f['hr'], 'No reliable match');
    $document = app(DocumentDrafts::class)->createOrUpdate($f['case'], DocumentType::DefenseLetter,
        ['ar' => 'طلب دفاع يدوي', 'en' => 'Manual defense request'], $f['hr']);
    expect($document->regulation_comparison_id)->toBe($comparison->id)
        ->and(app(RegulationExclusions::class)->for($document))->toContain($f['regulationUnit']->id);
});
