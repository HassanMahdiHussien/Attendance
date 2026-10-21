<?php

use App\Livewire\Cases\EditDocument;
use App\Livewire\Cases\EditRecommendations;
use App\Models\Citation;
use App\Models\Company;
use App\Models\Document;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\User;
use App\Services\Documents\DraftPreview;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function liveDraftFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $document = Document::factory()->create(['created_by' => $user->id]);
    foreach (['ar', 'en'] as $language) {
        $document->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'Saved text', 'is_authoritative' => $language === 'ar']);
    }

    return [$company, $user, $document];
}

test('a preview token renders unsaved bodies without changing contents or logs', function (): void {
    [, $user, $document] = liveDraftFixture();
    $contents = $document->contents()->pluck('body', 'language_code')->all();
    $events = DB::table('case_events')->count();
    $audits = DB::table('audit_logs')->count();
    $documents = DB::table('documents')->count();
    $token = app(DraftPreview::class)->store($document, $user, [
        'ar' => ['body' => 'New Arabic text'],
        'en' => ['body' => 'New English text <script>alert(1)</script>'],
    ]);

    $this->actingAs($user)->get(route('documents.preview', ['document' => $document, 'token' => $token]))
        ->assertOk()->assertSee('New Arabic text')->assertSee('New English text')
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>', false)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; img-src data:; style-src 'unsafe-inline'; font-src data:; sandbox; frame-ancestors 'self'");
    expect($document->fresh()->contents()->pluck('body', 'language_code')->all())->toBe($contents)
        ->and(DB::table('case_events')->count())->toBe($events)
        ->and(DB::table('audit_logs')->count())->toBe($audits)
        ->and(DB::table('documents')->count())->toBe($documents);
});

test('preview tokens are bound to their user document and company', function (): void {
    [$company, $user, $document] = liveDraftFixture();
    $token = app(DraftPreview::class)->store($document, $user, ['en' => ['body' => 'Private unsaved text']]);
    $otherUser = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $otherDocument = Document::factory()->create(['company_id' => $company->id, 'case_id' => $document->case_id,
        'version' => 2, 'created_by' => $user->id]);
    foreach (['ar', 'en'] as $language) {
        $otherDocument->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'Other saved text', 'is_authoritative' => $language === 'ar']);
    }

    $this->actingAs($otherUser)->get(route('documents.preview', ['document' => $document, 'token' => $token]))
        ->assertOk()->assertDontSee('Private unsaved text');
    $this->actingAs($user)->get(route('documents.preview', ['document' => $otherDocument, 'token' => $token]))
        ->assertOk()->assertDontSee('Private unsaved text');
    $foreignCompany = Company::factory()->create();
    $foreignUser = User::factory()->create(['company_id' => $foreignCompany->id, 'primary_role' => 'company_admin']);
    $this->actingAs($foreignUser)->get(route('documents.preview', ['document' => $document, 'token' => $token]))
        ->assertNotFound();
    expect(app(DraftPreview::class)->contents($token, $document, $foreignUser))->toBeNull();

    Cache::put('draft-preview:'.$token, [
        'user_id' => $user->id, 'document_id' => $document->id,
        'company_id' => $foreignCompany->id, 'contents' => ['en' => ['body' => 'Private unsaved text']],
    ], now()->addMinutes(30));
    $this->actingAs($user)->get(route('documents.preview', ['document' => $document, 'token' => $token]))
        ->assertOk()->assertDontSee('Private unsaved text');
});

test('locked documents reject preview even with a valid token and invalid unsaved markers use the missing page', function (): void {
    [, $user, $document] = liveDraftFixture();
    $token = app(DraftPreview::class)->store($document, $user, [
        'ar' => ['body' => 'Saved text'], 'en' => ['body' => 'Bad [[cite:nope]]'],
    ]);
    $this->actingAs($user)->get(route('documents.preview', ['document' => $document, 'token' => $token]))
        ->assertStatus(422);
    $document->update(['is_locked' => true]);
    expect(app(DraftPreview::class)->contents($token, $document->fresh(), $user))->toBeNull();
    $this->actingAs($user)->get(route('documents.preview', ['document' => $document, 'token' => $token]))
        ->assertStatus(409);
});

test('unsaved citation markers use the existing citation renderer', function (): void {
    [, $user, $document] = liveDraftFixture();
    $law = Law::factory()->create(['kind' => 'statute', 'category' => 'social_security', 'approval_status' => 'approved']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'article_number' => '43']);
    Citation::factory()->create(['document_id' => $document->id, 'legal_unit_id' => $unit->id,
        'claim_hash' => hash('sha256', "Saved text [[cite:{$unit->id}]]."), 'relevance_check' => 'supports']);
    $token = app(DraftPreview::class)->store($document, $user, [
        'en' => ['body' => "New wording [[cite:{$unit->id}]]."],
    ]);
    $this->actingAs($user)->get(route('documents.preview', ['document' => $document, 'token' => $token]))
        ->assertOk()->assertSee('Article 43')->assertDontSee('[[cite:');
});

test('editing a body refreshes the preview URL and marks the draft unsaved', function (): void {
    [, $user, $document] = liveDraftFixture();
    $screen = Livewire::actingAs($user)->test(EditDocument::class, [
        'case' => (string) $document->case_id, 'document' => (string) $document->id,
    ])->set('bodies.en', 'Typed without saving')
        ->assertSet('hasUnsavedChanges', true)
        ->assertSee('Unsaved changes')
        ->assertSee('token=', false)
        ->assertSee('v=1', false);
    $screen->set('bodies.en', 'Saved text')->assertSet('hasUnsavedChanges', false)
        ->assertSee('v=2', false);
    expect($document->fresh()->contents()->where('language_code', 'en')->first()->body)->toBe('Saved text');
});

test('recommendations preview refreshes when either text field changes', function (): void {
    [, $user, $document] = liveDraftFixture();
    $document->update(['document_type' => 'recommendations']);
    $document->contents()->update(['secondary_body' => 'Saved recommendation']);
    $screen = Livewire::actingAs($user)->test(EditRecommendations::class, [
        'case' => (string) $document->case_id, 'document' => (string) $document->id,
    ])->set('findings.en', 'New finding')->assertSet('previewVersion', 1)
        ->assertSee('Unsaved changes');
    $screen->set('recommendations.en', 'New recommendation')->assertSet('previewVersion', 2)
        ->assertSee('v=2', false);
    expect($document->fresh()->contents()->where('language_code', 'en')->first()->secondary_body)
        ->toBe('Saved recommendation');
});
