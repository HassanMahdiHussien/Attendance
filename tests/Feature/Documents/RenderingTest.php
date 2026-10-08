<?php

use App\Documents\DocumentRenderer;
use App\Documents\IraqiDate;
use App\Documents\MissingDocumentFact;
use App\Documents\TemplateResolver;
use App\Documents\TemplateValidator;
use App\Enums\EmployeeGender;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\IssuanceBlocked;
use App\Models\Company;
use App\Models\CompanyLetterhead;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\User;
use App\Services\Documents\DocumentIssuer;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function renderingFixture(string $gender = 'male'): array
{
    $company = Company::factory()->create(['name' => 'Pilot Company']);
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $document = Document::factory()->create(['created_by' => $actor->id]);
    $document->disciplinaryCase->employee->update(['name' => 'Ali Hassan', 'gender' => $gender,
        'position' => 'Engineer', 'hire_date' => '2020-01-18']);
    foreach (['ar', 'en'] as $language) {
        $document->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'An allegation with **bold** detail. <script>alert(1)</script>',
            'is_authoritative' => $language === 'ar']);
    }

    return [$company, $actor, $document];
}

test('Iraqi dates use the specified month names and Western digits', function (): void {
    $date = CarbonImmutable::parse('2026-06-18');
    expect(IraqiDate::format($date, 'ar'))->toBe('18 – حزيران – 2026')
        ->and(IraqiDate::format($date, 'en'))->toBe('18 – June – 2026');
});

test('template validation rejects unknown tokens, malformed gender groups and missing required facts', function (): void {
    $blocks = require app_path('Documents/Defaults/defense_letter.en.php');
    app(TemplateValidator::class)->validate('defense_letter', $blocks);
    $blocks['opening'] .= ' {employe_name}';
    expect(fn () => app(TemplateValidator::class)->validate('defense_letter', $blocks))
        ->toThrow(ValidationException::class);
    $blocks['opening'] = '{m:works} since {hire_date} as {position}';
    expect(fn () => app(TemplateValidator::class)->validate('defense_letter', $blocks))
        ->toThrow(ValidationException::class);
});

test('draft rendering uses provisional reference and watermark; issued rendering freezes escaped content', function (): void {
    Queue::fake();
    [, $actor, $document] = renderingFixture();
    $renderer = app(DocumentRenderer::class);
    $draft = $renderer->render($document, true);
    expect($draft)->toContain('مسودة / DRAFT', 'dir="rtl"', 'dir="ltr"', 'Pilot Company', 'CONFIDENTIAL', 'An allegation')
        ->not->toContain('<script>');
    expect(strpos($draft, 'lang="ar"'))->toBeLessThan(strpos($draft, 'lang="en"'));
    $issued = app(DocumentIssuer::class)->issue($document, $actor);
    expect($issued->rendered_html)->not->toContain('مسودة / DRAFT')
        ->and($issued->render_snapshot['renderer'])->toBe('v1')
        ->and($issued->pdf_status)->toBe('pending');
    $frozen = $issued->rendered_html;
    $issued->disciplinaryCase->employee->update(['name' => 'Changed Name']);
    expect($issued->fresh()->rendered_html)->toBe($frozen);
});

test('interface locale does not change document rendering or frozen issued content', function (): void {
    Queue::fake();
    [, $actor, $document] = renderingFixture();
    $renderer = app(DocumentRenderer::class);

    app()->setLocale('ar');
    $arabicInterfaceDraft = $renderer->render($document, true);
    app()->setLocale('en');
    expect($renderer->render($document, true))->toBe($arabicInterfaceDraft);

    app()->setLocale('ar');
    $issued = app(DocumentIssuer::class)->issue($document, $actor);
    $frozen = $issued->rendered_html;
    app()->setLocale('en');
    expect($issued->fresh()->rendered_html)->toBe($frozen)
        ->and($frozen)->toContain('lang="ar"', 'lang="en"');
});

test('female grammar is selected and unspecified gender blocks issuance', function (): void {
    Queue::fake();
    [, $actor, $document] = renderingFixture('female');
    expect(app(DocumentRenderer::class)->render($document, true))->toContain('عزيزتي السيدة', 'أنتِ تعملين', 'Dear Ms');
    $document->disciplinaryCase->employee->update(['gender' => EmployeeGender::Unspecified]);
    $this->actingAs($actor)->get(route('documents.preview', $document))->assertStatus(422)
        ->assertSee('employee.gender');
    expect(fn () => app(DocumentIssuer::class)->issue($document, $actor))
        ->toThrow(MissingDocumentFact::class, 'employee.gender');
});

test('bare article numbers and unsupported marker IDs block rendering', function (): void {
    [, , $document] = renderingFixture();
    $content = $document->contents()->where('language_code', 'en')->first();
    $content->update(['body' => 'Article 43 applies.']);
    expect(fn () => app(DocumentRenderer::class)->render($document, true))->toThrow(IssuanceBlocked::class);
    $content->update(['body' => 'See [[cite:999999]].']);
    expect(fn () => app(DocumentRenderer::class)->render($document, true))->toThrow(IssuanceBlocked::class);
});

test('company versions override defaults and both settings remain append only', function (): void {
    [$company] = renderingFixture();
    $blocks = require app_path('Documents/Defaults/defense_letter.en.php');
    $blocks['cc_value'] = 'Company review team';
    $version = DocumentTemplate::factory()->create(['company_id' => $company->id,
        'document_type' => 'defense_letter', 'language_code' => 'en', 'version' => 2,
        'blocks' => $blocks, 'options' => ['show_footer' => true, 'header_mode' => 'logo_text']]);
    $resolved = app(TemplateResolver::class)->resolve($company->id, 'defense_letter', 'en');
    expect($resolved['version'])->toBe(2)->and($resolved['blocks']['cc_value'])->toBe('Company review team');
    expect(fn () => $version->update(['version' => 3]))->toThrow(ImmutableRecordException::class);
    $letterhead = CompanyLetterhead::query()->where('company_id', $company->id)->where('language_code', 'en')->first();
    expect(fn () => $letterhead->update(['name' => 'Changed']))->toThrow(ImmutableRecordException::class);
});
