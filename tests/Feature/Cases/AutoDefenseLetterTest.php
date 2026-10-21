<?php

use App\Ai\ClaudeClient;
use App\Ai\PayloadBuilder;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Jobs\DraftDocumentWithAi;
use App\Jobs\SuggestRegulationComparison;
use App\Livewire\Cases\EditDocument;
use App\Livewire\Cases\ShowCase;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\RegulationComparison;
use App\Models\User;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\IncidentService;
use App\Services\Cases\NextAction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Fakes\FakeClaudeClient;

function autoDefenseFixture(bool $key = true): array
{
    Bus::fake([DraftDocumentWithAi::class, SuggestRegulationComparison::class]);
    config()->set('services.anthropic.key', $key ? 'test-key' : '');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    completeCompanySetup($company);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id,
        'violation_place_freetext' => 'Warehouse']);

    return compact('company', 'hr', 'law', 'case');
}

test('opening queues one defense draft for the opening HR user and does not issue it', function (): void {
    $f = autoDefenseFixture();
    $case = app(CaseOpening::class)->open($f['case'], $f['hr']);
    Bus::assertDispatchedTimes(DraftDocumentWithAi::class, 1);
    Bus::assertDispatched(DraftDocumentWithAi::class, fn ($job) => $job->caseId === $case->id
        && $job->actorId === $f['hr']->id && $job->type === DocumentType::DefenseLetter);
    expect($case->fresh()->status)->toBe(CaseStatus::AiDraftingDefenseLetter)
        ->and($case->fresh()->defense_letter_issued_at)->toBeNull()
        ->and($case->documents()->count())->toBe(0)
        ->and(app(NextAction::class)->for($case->fresh(), $f['hr'])->key)->toBe('defense_drafting');
});

test('the queued draft becomes a reviewable letter with its notes and stays unissued', function (): void {
    $f = autoDefenseFixture();
    $case = app(CaseOpening::class)->open($f['case'], $f['hr']);
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'وقع الحادث وفق الوقائع المسجلة.'],
        'en' => ['body' => 'The incident follows the recorded facts.']], 'notes' => ['Check the date.']]);
    app()->instance(ClaudeClient::class, $fake);
    app()->call([new DraftDocumentWithAi($case->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $case->fresh()->ai_request_id), 'handle']);
    $document = $case->documents()->firstOrFail();
    expect($case->fresh()->status)->toBe(CaseStatus::Open)
        ->and($case->fresh()->defense_letter_issued_at)->toBeNull()
        ->and($document->is_locked)->toBeFalse()
        ->and(app(NextAction::class)->for($case->fresh(), $f['hr'])->key)->toBe('defense_draft_ready');
    Livewire::actingAs($f['hr'])->test(EditDocument::class, ['case' => (string) $case->id,
        'document' => (string) $document->id])->assertSee('Check the date.')
        ->assertSee(__('Review and continue'));
});

test('opening without a key retains the manual editor', function (): void {
    $f = autoDefenseFixture(false);
    $case = app(CaseOpening::class)->open($f['case'], $f['hr']);
    Bus::assertNotDispatched(DraftDocumentWithAi::class);
    expect($case->fresh()->status)->toBe(CaseStatus::Open);
    Livewire::actingAs($f['hr'])->test(EditDocument::class, ['case' => (string) $case->id, 'document' => 'new'])
        ->assertSee(__('Write it myself'))->call('writeManually')->assertSee(__('Letter text'));
});

test('the editor primary action opens confirmation after human review without issuing', function (): void {
    $f = autoDefenseFixture(false);
    $case = app(CaseOpening::class)->open($f['case'], $f['hr']);
    Livewire::actingAs($f['hr'])->test(EditDocument::class, ['case' => (string) $case->id, 'document' => 'new'])
        ->call('writeManually')->set('bodies.ar', 'طلب دفاع بشأن الواقعة المسجلة.')
        ->set('bodies.en', 'A request for a response to the recorded incident.')
        ->call('submit')->assertRedirect();
    expect($case->fresh()->status)->toBe(CaseStatus::DefenseLetterReadyForReview)
        ->and($case->fresh()->defense_letter_issued_at)->toBeNull()
        ->and($case->documents()->firstOrFail()->is_locked)->toBeFalse();
});

test('failed drafting restores the manual path', function (): void {
    $f = autoDefenseFixture();
    $case = app(CaseOpening::class)->open($f['case'], $f['hr']);
    (new DraftDocumentWithAi($case->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $case->fresh()->ai_request_id))->failed(new RuntimeException('Unavailable'));
    expect($case->fresh()->status)->toBe(CaseStatus::Open)
        ->and($case->fresh()->ai_request_id)->toBeNull()
        ->and(app(NextAction::class)->for($case->fresh(), $f['hr'])->key)->toBe('defense_draft_failed');
});

test('approved regulation waits for comparison confirmation then queues the letter', function (): void {
    $f = autoDefenseFixture();
    $regulation = Law::factory()->create(['kind' => 'internal_regulation', 'company_id' => $f['company']->id]);
    $unit = LegalUnit::factory()->create(['law_id' => $regulation->id, 'status' => 'verified_text']);
    DB::table('legal_unit_approvals')->insert(['company_id' => $f['company']->id,
        'legal_unit_id' => $unit->id, 'approved_by' => $f['hr']->id, 'created_at' => now()]);
    $case = app(CaseOpening::class)->open($f['case'], $f['hr']);
    Bus::assertNotDispatched(DraftDocumentWithAi::class);
    $comparison = RegulationComparison::firstOrFail();
    $comparison->forceFill(['status' => 'ready'])->save();
    // What SuggestRegulationComparison does when it finishes; the opened instance is stale, so reload it first.
    $case = $case->fresh();
    $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
        'ai_request_from_status' => null, 'ai_request_started_at' => null])->save();
    expect(app(NextAction::class)->for($case->fresh(), $f['hr'])->key)->toBe('check_regulation_comparison');
    Livewire::actingAs($f['hr'])->test(EditDocument::class, ['case' => (string) $case->id, 'document' => 'new'])
        ->set('comparisonSkipReason', 'No reliable match')->call('skipComparison');
    Bus::assertDispatchedTimes(DraftDocumentWithAi::class, 1);
});

test('incident opening queues defense drafts without employee descriptions', function (): void {
    $f = autoDefenseFixture();
    $first = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['case']->branch_id]);
    $second = Employee::factory()->create(['company_id' => $f['company']->id,
        'branch_id' => $f['case']->branch_id, 'name' => 'Other Employee']);
    $incident = app(IncidentService::class)->createForEmployees([$first->id, $second->id], [
        'branch_id' => $f['case']->branch_id, 'violation_time' => now(), 'violation_type' => 'Incident',
        'violation_place_freetext' => 'Warehouse',
        'description' => 'Other Employee was at the warehouse. A report was received.',
    ], $f['hr']);
    app(IncidentService::class)->openDrafts($incident, $f['hr']);
    $case = $incident->cases()->where('employee_id', $first->id)->firstOrFail();
    Bus::assertDispatchedTimes(DraftDocumentWithAi::class, 2);
    expect($case->case_specific_description)->toBeNull()
        ->and($case->status)->toBe(CaseStatus::AiDraftingDefenseLetter);
    $payload = app(PayloadBuilder::class)->build($case, DocumentType::DefenseLetter, collect());
    expect($payload['incident']['description'])->toContain('A report was received.')
        ->not->toContain('Other Employee');
});

test('an incident description naming another member is refused', function (): void {
    $f = autoDefenseFixture(false);
    $first = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['case']->branch_id]);
    $second = Employee::factory()->create(['company_id' => $f['company']->id,
        'branch_id' => $f['case']->branch_id, 'name' => 'Other Employee']);
    $incident = app(IncidentService::class)->createForEmployees([$first->id, $second->id], [
        'branch_id' => $f['case']->branch_id, 'violation_time' => now(), 'violation_type' => 'Incident',
        'violation_place_freetext' => 'Warehouse', 'description' => 'A report was received.',
    ], $f['hr']);
    $case = app(IncidentService::class)->openDrafts($incident, $f['hr']);
    Livewire::actingAs($f['hr'])->test(ShowCase::class, ['case' => (string) $case->id])
        ->set('caseDescription', 'Other Employee was there.')->call('saveCaseDescription')
        ->assertHasErrors('caseDescription');
    expect($case->fresh()->case_specific_description)->toBeNull();
});

test('an optional incident description can be saved after a draft exists', function (): void {
    $f = autoDefenseFixture(false);
    $first = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['case']->branch_id]);
    $second = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['case']->branch_id]);
    $incident = app(IncidentService::class)->createForEmployees([$first->id, $second->id], [
        'branch_id' => $f['case']->branch_id, 'violation_time' => now(), 'violation_type' => 'Incident',
        'violation_place_freetext' => 'Warehouse', 'description' => 'A report was received.',
    ], $f['hr']);
    $case = app(IncidentService::class)->openDrafts($incident, $f['hr']);
    Livewire::actingAs($f['hr'])->test(EditDocument::class, ['case' => (string) $case->id, 'document' => 'new'])
        ->call('writeManually')->set('bodies.ar', 'وقائع مسجلة.')->set('bodies.en', 'Recorded facts.')
        ->call('save');
    Livewire::actingAs($f['hr'])->test(ShowCase::class, ['case' => (string) $case->id])
        ->set('caseDescription', 'The employee was present.')->call('saveCaseDescription')->assertHasNoErrors();
    expect($case->fresh()->case_specific_description)->toBe('The employee was present.');
});

test('saving an optional description queues a defense draft when none exists', function (): void {
    $f = autoDefenseFixture(false);
    $first = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['case']->branch_id]);
    $second = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['case']->branch_id]);
    $incident = app(IncidentService::class)->createForEmployees([$first->id, $second->id], [
        'branch_id' => $f['case']->branch_id, 'violation_time' => now(), 'violation_type' => 'Incident',
        'violation_place_freetext' => 'Warehouse', 'description' => 'A report was received.',
    ], $f['hr']);
    $case = app(IncidentService::class)->openDrafts($incident, $f['hr']);
    config()->set('services.anthropic.key', 'test-key');
    Livewire::actingAs($f['hr'])->test(ShowCase::class, ['case' => (string) $case->id])
        ->set('caseDescription', 'The employee was present.')->call('saveCaseDescription')->assertHasNoErrors();
    Bus::assertDispatchedTimes(DraftDocumentWithAi::class, 1);
});

test('documents appear before actions and a draft row opens only the editor', function (): void {
    $f = autoDefenseFixture();
    $case = app(CaseOpening::class)->open($f['case'], $f['hr']);
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'وقائع مسجلة.'],
        'en' => ['body' => 'Recorded facts.']], 'notes' => []]);
    app()->instance(ClaudeClient::class, $fake);
    app()->call([new DraftDocumentWithAi($case->id, $f['company']->id, $f['hr']->id,
        DocumentType::DefenseLetter, $case->fresh()->ai_request_id), 'handle']);
    $html = Livewire::actingAs($f['hr'])->test(ShowCase::class, ['case' => (string) $case->id])->html();
    expect(strpos($html, 'id="next-documents"'))->toBeLessThan(strpos($html, 'id="next-actions"'));
    expect($html)->toContain(__('Edit draft'))->not->toContain(__('Download PDF'));
});
