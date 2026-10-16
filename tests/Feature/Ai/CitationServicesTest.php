<?php

use App\Ai\AiUnavailable;
use App\Ai\ClaudeClient;
use App\Documents\CitationGate;
use App\Documents\CitationRenderer;
use App\Exceptions\IssuanceBlocked;
use App\Jobs\CheckCitationRelevance;
use App\Models\Company;
use App\Models\Document;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Documents\CitationOverride;
use App\Services\Documents\CitationRefresh;
use App\Services\Documents\CitationSync;
use App\Services\Documents\RelevanceCheck;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\FakeClaudeClient;

function aiCitationFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $document = Document::factory()->create(['created_by' => $user->id]);
    $law = Law::factory()->create(['category' => 'social_security', 'approval_status' => 'approved',
        'name' => 'القانون', 'name_en' => 'Social Security Law']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'article_number' => '2',
        'clause_path' => '2/ثانياً/ب', 'official_text' => 'Official legal text']);

    return compact('company', 'user', 'document', 'law', 'unit');
}

test('two claims citing one unit have separate rows and an edit clears only the changed claim', function (): void {
    Bus::fake([CheckCitationRelevance::class]);
    $f = aiCitationFixture();
    $document = $f['document'];
    $id = $f['unit']->id;
    $content = $document->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'ar',
        'body' => "الادعاء الأول [[cite:{$id}]]. الادعاء الثاني [[cite:{$id}]].", 'is_authoritative' => true]);
    $document->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'en',
        'body' => "First claim [[cite:{$id}]]. Second claim [[cite:{$id}]].", 'is_authoritative' => false]);
    app(CitationSync::class)->sync($document);
    $rows = $document->citations()->orderBy('id')->get();
    expect($rows)->toHaveCount(2);
    foreach ($rows as $row) {
        $row->update(['relevance_check' => 'supports']);
    }
    app(CitationGate::class)->assertIssuable($document);
    $content->update(['body' => "الادعاء الأول [[cite:{$id}]]. الادعاء المعدل [[cite:{$id}]]."]);
    app(CitationSync::class)->sync($document);
    expect($document->citations()->count())->toBe(2)
        ->and($document->citations()->where('relevance_check', 'supports')->count())->toBe(1)
        ->and($document->citations()->whereNull('relevance_check')->count())->toBe(1);
    expect(fn () => app(CitationGate::class)->assertIssuable($document))->toThrow(IssuanceBlocked::class);
});

test('unchecked citation blocks until a reviewer gives a logged reason', function (): void {
    Bus::fake([CheckCitationRelevance::class]);
    $f = aiCitationFixture();
    $id = $f['unit']->id;
    $f['document']->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'ar',
        'body' => "إفادة موثقة [[cite:{$id}]].", 'is_authoritative' => true]);
    app(CitationSync::class)->sync($f['document']);
    $citation = $f['document']->citations()->firstOrFail();
    expect(fn () => app(CitationGate::class)->assertIssuable($f['document']))->toThrow(IssuanceBlocked::class);
    // A draft preview may show the unreviewed citation so HR can read it before deciding; issuance may not.
    app(CitationGate::class)->assertIssuable($f['document'], true);
    app(CitationOverride::class)->override($citation, $f['user'], 'Checked the official text with counsel.');
    app(CitationGate::class)->assertIssuable($f['document']);
    expect($citation->fresh()->override_reason)->toBe('Checked the official text with counsel.');
});

test('English clause rendering uses numbers and abjad letters on real paths', function (): void {
    $f = aiCitationFixture();
    $f['document']->citations()->create(['company_id' => $f['company']->id,
        'legal_unit_id' => $f['unit']->id, 'claim_sentence' => 'Claim',
        'claim_hash' => hash('sha256', 'Claim')]);
    expect(app(CitationRenderer::class)->replace($f['document'], '[[cite:'.$f['unit']->id.']]', 'en'))
        ->toBe('Article 2(2)(b) of Social Security Law');
    $another = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'article_number' => '18',
        'clause_path' => '18/ثامن عشر']);
    $f['document']->citations()->create(['company_id' => $f['company']->id,
        'legal_unit_id' => $another->id, 'claim_sentence' => 'Another claim',
        'claim_hash' => hash('sha256', 'Another claim')]);
    expect(app(CitationRenderer::class)->replace($f['document'], '[[cite:'.$another->id.']]', 'en'))
        ->toBe('Article 18(18) of Social Security Law');
    $first = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'article_number' => '5',
        'clause_path' => '5/أولاً']);
    $f['document']->citations()->create(['company_id' => $f['company']->id,
        'legal_unit_id' => $first->id, 'claim_sentence' => 'First clause',
        'claim_hash' => hash('sha256', 'First clause')]);
    expect(app(CitationRenderer::class)->replace($f['document'], '[[cite:'.$first->id.']]', 'en'))
        ->toBe('Article 5(1) of Social Security Law');
});

test('relevance is checked per claim and unavailable claims remain unchecked', function (): void {
    Bus::fake([CheckCitationRelevance::class]);
    $f = aiCitationFixture();
    $id = $f['unit']->id;
    $f['document']->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'ar',
        'body' => "الادعاء الأول [[cite:{$id}]]. الادعاء الثاني [[cite:{$id}]]. الادعاء الثالث [[cite:{$id}]]. الادعاء الرابع [[cite:{$id}]].",
        'is_authoritative' => true]);
    app(CitationSync::class)->sync($f['document']);
    $fake = new FakeClaudeClient;
    $fake->push(['result' => 'supports', 'reason' => 'Supported']);
    $fake->push(['result' => 'partial', 'reason' => 'Partly supported']);
    $fake->push(['result' => 'does_not_support', 'reason' => 'Unsupported']);
    $fake->push(new AiUnavailable('Unavailable'));
    app()->instance(ClaudeClient::class, $fake);
    (new CheckCitationRelevance($f['document']->id, $f['company']->id))->handle($fake,
        app(CitationRenderer::class), app(AuditLogger::class), app(CaseEventLogger::class));
    expect($f['document']->citations()->where('relevance_check', 'supports')->count())->toBe(1)
        ->and($f['document']->citations()->where('relevance_check', 'partial')->count())->toBe(1)
        ->and($f['document']->citations()->where('relevance_check', 'does_not_support')->count())->toBe(1)
        ->and($f['document']->citations()->whereNull('relevance_check')->count())->toBe(1)
        ->and($fake->requests[0]['messages'][0]['content'])->toContain('Official text: Official legal text');
    expect(fn () => app(CitationGate::class)->assertIssuable($f['document']))->toThrow(IssuanceBlocked::class);
    foreach ($f['document']->citations()->where('relevance_check', '!=', 'supports')->orWhereNull('relevance_check')->get() as $citation) {
        app(CitationOverride::class)->override($citation, $f['user'], 'Reviewed the official text.');
    }
    app(CitationGate::class)->assertIssuable($f['document']);
    app(RelevanceCheck::class)->clear($f['document']);
    expect($f['document']->citations()->whereNull('relevance_check')->count())->toBe(4)
        ->and($f['document']->citations()->where('overridden', true)->count())->toBe(0);
});

test('a verdict with a long reason is kept and only the reason is shortened', function (): void {
    Bus::fake([CheckCitationRelevance::class]);
    $f = aiCitationFixture();
    $f['document']->contents()->create(['company_id' => $f['company']->id, 'language_code' => 'ar',
        'body' => "الادعاء [[cite:{$f['unit']->id}]].", 'is_authoritative' => true]);
    app(CitationSync::class)->sync($f['document']);
    $fake = new FakeClaudeClient;
    // Real relevance reasons from the API were 299-446 characters long.
    $fake->push(['result' => 'does_not_support', 'reason' => str_repeat('The cited clause concerns a different duty. ', 20)]);
    (new CheckCitationRelevance($f['document']->id, $f['company']->id))->handle($fake,
        app(CitationRenderer::class), app(AuditLogger::class), app(CaseEventLogger::class));
    $citation = $f['document']->citations()->sole();
    expect($citation->relevance_check?->value)->toBe('does_not_support')
        ->and(mb_strlen($citation->relevance_reason))->toBeLessThanOrEqual(503);
});

test('refresh rewrites a superseded marker in every language and creates an unchecked claim', function (): void {
    Bus::fake([CheckCitationRelevance::class]);
    $f = aiCitationFixture();
    $labor = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
    $f['document']->disciplinaryCase->update(['law_version_id' => $labor->id]);
    DB::statement('SET @displanery_workflow_transition = 1');
    try {
        DB::table('cases')->where('id', $f['document']->case_id)->update(['status' => 'open']);
    } finally {
        DB::statement('SET @displanery_workflow_transition = NULL');
    }
    $id = $f['unit']->id;
    foreach (['ar' => "ادعاء موثق [[cite:{$id}]].", 'en' => "Documented claim [[cite:{$id}]]."] as $language => $body) {
        $f['document']->contents()->create(['company_id' => $f['company']->id,
            'language_code' => $language, 'body' => $body, 'is_authoritative' => $language === 'ar']);
    }
    app(CitationSync::class)->sync($f['document']);
    $f['document']->citations()->firstOrFail()->update(['relevance_check' => 'supports']);
    $new = LegalUnit::factory()->create(['law_id' => $f['law']->id,
        'article_number' => '2', 'version' => 2, 'previous_version_id' => $id]);
    $f['unit']->update(['superseded_by_id' => $new->id]);
    app(CitationRefresh::class)->replace($f['document'], $f['unit'], $f['user']);
    expect($f['document']->contents()->where('language_code', 'ar')->firstOrFail()->body)->toContain("[[cite:{$new->id}]]")
        ->and($f['document']->contents()->where('language_code', 'en')->firstOrFail()->body)->toContain("[[cite:{$new->id}]]")
        ->and($f['document']->citations()->count())->toBe(1)
        ->and($f['document']->citations()->firstOrFail()->relevance_check)->toBeNull();
});
