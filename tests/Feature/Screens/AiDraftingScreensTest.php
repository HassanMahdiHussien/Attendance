<?php

use App\Ai\AiUnavailable;
use App\Ai\ClaudeClient;
use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Exceptions\TransitionBlocked;
use App\Filament\Platform\Resources\LawResource;
use App\Jobs\CheckCitationRelevance;
use App\Jobs\DraftDocumentWithAi;
use App\LegalLibrary\LawManagement;
use App\Livewire\Cases\ConfirmDocument;
use App\Livewire\Cases\EditDocument;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\LegalUnit;
use App\Models\User;
use App\Services\Cases\CaseOpening;
use App\Services\Documents\DocumentDrafts;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Fakes\FakeClaudeClient;

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

function aiScreenCase(): array
{
    Bus::fake([DraftDocumentWithAi::class, CheckCitationRelevance::class]);
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2026-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id, 'violation_place_freetext' => 'Warehouse']);
    $case = app(CaseOpening::class)->open($case, $admin);

    return compact('company', 'admin', 'law', 'case');
}

test('AI drafting button and action are limited to editors with visible cases', function (): void {
    $f = aiScreenCase();
    $assigned = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    $unassigned = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    $pool = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'committee_member']);
    $reviewer = User::factory()->create(['primary_role' => 'external_legal_reviewer',
        'law_firm_partner_id' => LawFirmPartner::factory()->create()->id]);
    $reporter = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'reporter']);
    $f['case']->update(['assigned_hr_user_id' => $assigned->id]);

    foreach ([$f['admin'], $assigned] as $editor) {
        $this->actingAs($editor);
        Livewire::test(EditDocument::class, ['case' => $f['case']->id, 'document' => 'new'])
            ->assertSee('Draft with AI');
    }
    foreach ([$unassigned, $pool, $reviewer, $reporter] as $outsider) {
        $this->actingAs($outsider);
        Livewire::test(EditDocument::class, ['case' => $f['case']->id, 'document' => 'new'])->assertNotFound();
    }
    $this->actingAs($f['admin']);
    Livewire::test(EditDocument::class, ['case' => $f['case']->id, 'document' => 'new'])
        ->call('requestAiDraft')->assertSee('Drafting…');
    expect($f['case']->fresh()->status)->toBe(CaseStatus::AiDraftingDefenseLetter);
    Bus::assertDispatched(DraftDocumentWithAi::class);
});

test('citation picker offers only citable units and refuses a forged selection', function (): void {
    $f = aiScreenCase();
    $allowed = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'article_number' => '10']);
    $blocked = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'article_number' => '11', 'status' => 'unverified']);
    $this->actingAs($f['admin']);
    Livewire::test(EditDocument::class, ['case' => $f['case']->id, 'document' => 'new'])
        ->call('openCitationPicker')->assertSee('Article 10')->assertDontSee('Article 11')
        ->call('selectCitation', $blocked->id)->assertNotFound();
    expect($allowed->id)->not->toBe($blocked->id);
});

test('an existing draft requires explicit replacement before AI is queued', function (): void {
    $f = aiScreenCase();
    $document = app(DocumentDrafts::class)->createOrUpdate($f['case'], DocumentType::DefenseLetter,
        ['ar' => 'طلب دفاع يدوي', 'en' => 'Manual defense request'], $f['admin']);
    $this->actingAs($f['admin']);
    $screen = Livewire::test(EditDocument::class, ['case' => $f['case']->id, 'document' => (string) $document->id]);
    $screen->call('requestAiDraft')->assertSee('This will replace the current text');
    expect($f['case']->fresh()->status)->toBe(CaseStatus::Open);
    $screen->call('requestAiDraft')->assertSee('Drafting…');
    expect($f['case']->fresh()->status)->toBe(CaseStatus::AiDraftingDefenseLetter);
});

test('polling loads a saved draft and reports a failed request', function (): void {
    $f = aiScreenCase();
    $this->actingAs($f['admin']);
    $screen = Livewire::test(EditDocument::class, ['case' => $f['case']->id, 'document' => 'new']);
    $screen->call('requestAiDraft');
    $token = $f['case']->fresh()->ai_request_id;
    $fake = new FakeClaudeClient;
    $fake->push(['languages' => ['ar' => ['body' => 'الوقائع المثبتة في المحضر.'],
        'en' => ['body' => 'The recorded facts of the incident.']], 'notes' => ['راجع الوقائع.']]);
    app()->instance(ClaudeClient::class, $fake);
    app()->call([new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['admin']->id,
        DocumentType::DefenseLetter, $token), 'handle']);
    $screen->call('pollAiDraft')->assertSee('The AI draft was saved');
    expect($screen->get('bodies.ar'))->toBe('الوقائع المثبتة في المحضر.');

    $screen->call('requestAiDraft')->call('requestAiDraft');
    $failed = new DraftDocumentWithAi($f['case']->id, $f['company']->id, $f['admin']->id,
        DocumentType::DefenseLetter, $f['case']->fresh()->ai_request_id);
    $failed->failed(new AiUnavailable('unavailable'));
    $screen->call('pollAiDraft')->assertSee('AI drafting failed');
});

test('citation override needs a reason and records an audit entry', function (): void {
    $f = aiScreenCase();
    $unit = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'article_number' => '10']);
    $document = app(DocumentDrafts::class)->createOrUpdate($f['case'], DocumentType::DefenseLetter,
        ['ar' => 'نص الطلب [[cite:'.$unit->id.']].', 'en' => 'Request text [[cite:'.$unit->id.']].'], $f['admin']);
    $citation = $document->citations()->firstOrFail();
    $this->actingAs($f['admin']);
    Livewire::test(EditDocument::class, ['case' => $f['case']->id, 'document' => (string) $document->id])
        ->call('overrideCitation', $citation->id)->assertHasErrors(['overrideReasons.'.$citation->id])
        ->set('overrideReasons.'.$citation->id, 'راجعت النص الرسمي يدوياً')->call('overrideCitation', $citation->id)
        ->assertHasNoErrors();
    expect($citation->fresh()->override_reason)->toBe('راجعت النص الرسمي يدوياً');
    $this->assertDatabaseHas('audit_logs', ['action' => 'citation.overridden']);
});

test('new editor actions reauthorize Livewire update requests after the account changes', function (): void {
    $f = aiScreenCase();
    $unit = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'article_number' => '10']);
    $document = app(DocumentDrafts::class)->createOrUpdate($f['case'], DocumentType::DefenseLetter,
        ['ar' => 'النص [[cite:'.$unit->id.']].', 'en' => 'Text [[cite:'.$unit->id.']].'], $f['admin']);
    $citation = $document->citations()->firstOrFail();
    $this->actingAs($f['admin']);
    $html = $this->get('/cases/'.$f['case']->id.'/documents/'.$document->id.'/edit')->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);
    $snapshot = html_entity_decode($match[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $reporter = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'reporter']);
    $this->actingAs($reporter);
    foreach ([
        ['requestAiDraft', []], ['pollAiDraft', []], ['openCitationPicker', []],
        ['closeCitationPicker', []], ['selectCitation', [$unit->id]],
        ['recheckCitations', []], ['overrideCitation', [$citation->id]],
        ['replaceCitation', [$unit->id]],
    ] as [$method, $params]) {
        $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => $method, 'params' => $params]],
        ]]], ['X-Livewire' => 'true'])->assertNotFound();
    }
});

test('platform law editing exposes English name and statute topics only to platform admins', function (): void {
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
    $company = Company::factory()->create();
    $companyAdmin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    app(TenantContext::class)->setAllCompanies();
    $law = Law::factory()->create(['kind' => 'statute']);
    $unit = LegalUnit::factory()->create(['law_id' => $law->id]);
    $this->actingAs($platform);
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    $this->get(LawResource::getUrl('edit', ['record' => $law], panel: 'platform'))
        ->assertOk()->assertSee('English law name');
    $platform->forceFill(['locale' => 'ar'])->save();
    $this->get(LawResource::getUrl('edit', ['record' => $law], panel: 'platform'))
        ->assertOk()->assertSee('اسم القانون بالإنجليزية');
    app(LawManagement::class)->update($law, $platform, ['name_en' => 'Labour Law']);
    app(LawManagement::class)->setUnitTopics($unit, $platform, ['discipline', 'leave']);
    expect($law->fresh()->name_en)->toBe('Labour Law')
        ->and($unit->topics()->count())->toBe(2);
    $this->actingAs($companyAdmin);
    expect($this->get(LawResource::getUrl('edit', ['record' => $law], panel: 'platform'))->status())->toBeIn([302, 403, 404]);
    expect(fn () => app(LawManagement::class)->setUnitTopics($unit, $companyAdmin, ['discipline']))
        ->toThrow(HttpException::class);
});

test('confirmation screen shows the Arabic citation gate message for an unchecked claim', function (): void {
    $f = aiScreenCase();
    $unit = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'article_number' => '10']);
    $drafts = app(DocumentDrafts::class);
    $document = $drafts->createOrUpdate($f['case'], DocumentType::DefenseLetter,
        ['ar' => 'طلب الدفاع', 'en' => 'Defense request'], $f['admin']);
    $drafts->submit($document, $f['admin']);
    $arabic = $document->contents()->where('language_code', 'ar')->firstOrFail();
    $arabic->update(['body' => 'طلب الدفاع [[cite:'.$unit->id.']].']);
    $document->citations()->create(['company_id' => $f['company']->id, 'legal_unit_id' => $unit->id,
        'claim_sentence' => 'طلب الدفاع [[cite:'.$unit->id.']].',
        'claim_hash' => hash('sha256', 'طلب الدفاع [[cite:'.$unit->id.']].'), 'overridden' => false]);
    $this->actingAs($f['admin']);
    Livewire::test(ConfirmDocument::class, ['case' => $f['case']->id, 'document' => $document->id])
        ->assertSee('يحتاج إلى فحص داعم أو تجاوز مسبب');
});

test('a first defense draft with an unchecked citation cannot be submitted', function (): void {
    $f = aiScreenCase();
    $unit = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'article_number' => '10']);
    $document = app(DocumentDrafts::class)->createOrUpdate($f['case'], DocumentType::DefenseLetter,
        ['ar' => 'طلب الدفاع [[cite:'.$unit->id.']].', 'en' => 'Defense request [[cite:'.$unit->id.']].'], $f['admin']);
    // The workflow guard runs CitationGate on this transition and reports its message as TransitionBlocked.
    expect(fn () => app(DocumentDrafts::class)->submit($document, $f['admin']))
        ->toThrow(TransitionBlocked::class, 'يحتاج إلى فحص داعم أو تجاوز مسبب');
    expect($f['case']->fresh()->status)->toBe(CaseStatus::DefenseLetterCitationCheck);
});
