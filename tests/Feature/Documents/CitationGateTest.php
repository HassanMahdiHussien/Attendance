<?php

use App\Documents\CitationGate;
use App\Exceptions\IssuanceBlocked;
use App\Models\Citation;
use App\Models\Company;
use App\Models\Document;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

function citationFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $document = Document::factory()->create();
    $content = $document->contents()->create(['company_id' => $company->id, 'language_code' => 'ar',
        'body' => '[[cite:1]]', 'is_authoritative' => true]);
    $law = Law::factory()->create(['kind' => 'statute', 'approval_status' => 'approved']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $document->disciplinaryCase->update(['law_version_id' => $law->id]);
    $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'article_number' => '43']);
    $content->update(['body' => "[[cite:{$unit->id}]]"]);
    $citation = Citation::factory()->create(['document_id' => $document->id, 'legal_unit_id' => $unit->id,
        'relevance_check' => 'supports']);

    return compact('company', 'document', 'content', 'law', 'unit', 'citation');
}

test('verified applicable supported citations pass and a bare article number fails', function (): void {
    $f = citationFixture();
    app(CitationGate::class)->assertIssuable($f['document']);
    $f['content']->update(['body' => 'المادة 43']);
    expect(fn () => app(CitationGate::class)->assertIssuable($f['document']))->toThrow(IssuanceBlocked::class);
});

test('jurisdiction, verification, supersession and relevance are checked at issuance', function (): void {
    $f = citationFixture();
    $gate = app(CitationGate::class);
    $f['law']->jurisdictions()->delete();
    expect(fn () => $gate->assertIssuable($f['document']))->toThrow(IssuanceBlocked::class);
    $f['law']->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $f['citation']->update(['relevance_check' => 'does_not_support']);
    expect(fn () => $gate->assertIssuable($f['document']))->toThrow(IssuanceBlocked::class);
    $f['citation']->update(['overridden' => true, 'overridden_by' => $f['document']->disciplinaryCase->opened_by_user_id,
        'overridden_at' => now()]);
    $gate->assertIssuable($f['document']);
    $new = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'previous_version_id' => $f['unit']->id,
        'version' => 2, 'article_number' => '43']);
    $f['unit']->update(['superseded_by_id' => $new->id]);
    expect($f['citation']->fresh()->legal_unit_id)->toBe($f['unit']->id);
    expect(fn () => $gate->assertIssuable($f['document']))->toThrow(IssuanceBlocked::class);
});

test('an unapproved internal regulation is not issuable', function (): void {
    $f = citationFixture();
    $regulation = Law::factory()->create(['kind' => 'internal_regulation', 'company_id' => $f['company']->id,
        'approval_status' => 'approved']);
    $unit = LegalUnit::factory()->create(['law_id' => $regulation->id, 'article_number' => '7']);
    $f['content']->update(['body' => "[[cite:{$unit->id}]]"]);
    DB::table('citations')->insert(['company_id' => $f['company']->id, 'document_id' => $f['document']->id,
        'legal_unit_id' => $unit->id, 'claim_sentence' => 'Legacy regulation citation', 'relevance_check' => 'supports']);
    expect(fn () => app(CitationGate::class)->assertIssuable($f['document']))->toThrow(IssuanceBlocked::class);
});

test('an unverified legal unit is rejected even if a legacy citation row points to it', function (): void {
    $f = citationFixture();
    $unit = LegalUnit::factory()->create(['law_id' => $f['law']->id, 'status' => 'unverified',
        'article_number' => '99']);
    $f['content']->update(['body' => "[[cite:{$unit->id}]]"]);
    DB::table('citations')->insert(['company_id' => $f['company']->id, 'document_id' => $f['document']->id,
        'legal_unit_id' => $unit->id, 'claim_sentence' => 'Legacy citation', 'relevance_check' => 'supports']);
    expect(fn () => app(CitationGate::class)->assertIssuable($f['document']))->toThrow(IssuanceBlocked::class);
});
