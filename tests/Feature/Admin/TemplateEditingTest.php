<?php

use App\Documents\DocumentRenderer;
use App\Documents\PdfGenerator;
use App\Filament\Admin\Pages\DocumentTemplates;
use App\Jobs\GenerateDocumentPdf;
use App\Models\AuditLog;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Employee;
use App\Models\User;
use App\Services\Documents\DocumentIssuer;
use App\Services\Documents\LetterheadService;
use App\Services\Documents\TemplateService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => 'company_admin']);
    actingInAdminTenant($this, $this->admin, $this->company);
});

test('template form fills code defaults and refills when document type or language changes', function (): void {
    $arabicDefense = require app_path('Documents/Defaults/defense_letter.ar.php');
    $englishDefense = require app_path('Documents/Defaults/defense_letter.en.php');
    $englishRecommendations = require app_path('Documents/Defaults/recommendations.en.php');

    Livewire::test(DocumentTemplates::class)
        ->assertSet('data.blocks.title', $arabicDefense['title'])
        ->assertSee('Opening paragraph')
        ->assertSee('opening')
        ->set('language', 'en')
        ->assertSet('data.blocks.title', $englishDefense['title'])
        ->assertSet('data.blocks.opening', $englishDefense['opening'])
        ->set('documentType', 'recommendations')
        ->assertSet('data.blocks.title', $englishRecommendations['title'])
        ->assertSet('data.blocks.mandate_sentence', $englishRecommendations['mandate_sentence']);
});

test('template form loads the active company version and unchanged saves create no version', function (): void {
    $blocks = require app_path('Documents/Defaults/defense_letter.en.php');
    $blocks['title'] = 'Company defense request';
    app(TemplateService::class)->saveVersion($this->company, 'defense_letter', 'en', $blocks,
        ['show_footer' => true, 'header_mode' => 'logo_text'], $this->admin);

    Livewire::test(DocumentTemplates::class)->set('language', 'en')
        ->assertSet('data.blocks.title', 'Company defense request')
        ->assertSet('data.blocks.opening', $blocks['opening'])
        ->call('save')->assertHasNoErrors();
    expect(DocumentTemplate::query()->where('document_type', 'defense_letter')->where('language_code', 'en')->count())->toBe(1);

    Livewire::test(DocumentTemplates::class)->call('save')->assertHasNoErrors();
    expect(DocumentTemplate::query()->count())->toBe(1);
});

test('Kurdish without a company template has empty blocks and a wording notice', function (): void {
    $this->company->update(['enabled_languages' => ['ar', 'en', 'ku']]);

    Livewire::test(DocumentTemplates::class)->set('language', 'ku')
        ->assertSet('data.blocks.title', '')
        ->assertSet('data.blocks.opening', '')
        ->assertSee('The company must provide template text')
        ->assertSee('Kurdish');
});

test('a new template version keeps the old version and an issued document frozen', function (): void {
    $document = Document::factory()->create(['created_by' => $this->admin->id]);
    $document->disciplinaryCase->employee->update(['gender' => 'male']);
    foreach (['ar', 'en'] as $language) {
        $document->contents()->create(['company_id' => $this->company->id, 'language_code' => $language,
            'body' => 'Human-entered facts', 'is_authoritative' => $language === 'ar']);
    }
    $service = app(TemplateService::class);
    $blocks = require app_path('Documents/Defaults/defense_letter.en.php');
    $first = $service->saveVersion($this->company, 'defense_letter', 'en', $blocks,
        ['show_footer' => true, 'header_mode' => 'logo_text'], $this->admin);
    $issued = app(DocumentIssuer::class)->issue($document, $this->admin);
    $html = $issued->rendered_html;
    $job = new GenerateDocumentPdf($issued->id, $issued->company_id);
    $job->handle(app(PdfGenerator::class));
    $pdfPath = $issued->fresh()->pdf_file_path;
    $pdf = Storage::disk('local')->get($pdfPath);
    $blocks['title'] = 'UPDATED DEFENSE REQUEST';
    $second = $service->saveVersion($this->company, 'defense_letter', 'en', $blocks,
        ['show_footer' => true, 'header_mode' => 'logo_text'], $this->admin);

    expect($first->version)->toBe(1)->and($second->version)->toBe(2)
        ->and($first->fresh()->blocks['title'])->toBe('DEFENSE REQUEST')
        ->and($issued->fresh()->rendered_html)->toBe($html)
        ->and($issued->fresh()->pdf_status)->toBe('ready')
        ->and(Storage::disk('local')->get($pdfPath))->toBe($pdf)
        ->and(AuditLog::query()->where('action', 'document_template.version_created')->count())->toBe(2);
});

test('template and letterhead services reject a different company', function (): void {
    $other = Company::factory()->create();
    $blocks = require app_path('Documents/Defaults/defense_letter.en.php');
    expect(fn () => app(TemplateService::class)->saveVersion($other, 'defense_letter', 'en', $blocks,
        [], $this->admin))->toThrow(HttpException::class);
    expect(fn () => app(LetterheadService::class)->saveVersion($other, 'en', [
        'name' => 'Other', 'footer_text' => 'CONFIDENTIAL', 'header_mode' => 'logo_text',
    ], $this->admin))->toThrow(HttpException::class);
});

test('template form refuses invalid placeholders and HR cannot invoke save', function (): void {
    $blocks = require app_path('Documents/Defaults/defense_letter.en.php');
    foreach (['{unknown}', '{m:only}', '{m:yes|f:no'] as $invalid) {
        $copy = $blocks;
        $copy['opening'] .= ' '.$invalid;
        expect(fn () => app(TemplateService::class)->saveVersion($this->company, 'defense_letter', 'en', $copy,
            [], $this->admin))->toThrow(ValidationException::class);
    }
    $copy = $blocks;
    $copy['salutation'] = 'Dear employee';
    expect(fn () => app(TemplateService::class)->saveVersion($this->company, 'defense_letter', 'en', $copy,
        [], $this->admin))->toThrow(ValidationException::class);

    foreach ([
        ['opening', $blocks['opening'].' {unknown}'],
        ['opening', '{m:only} {hire_date} {position}'],
        ['salutation', 'Dear employee'],
    ] as [$field, $value]) {
        actingInAdminTenant($this, $this->admin, $this->company);
        Livewire::test(DocumentTemplates::class)->set('language', 'en')
            ->set("data.blocks.{$field}", $value)->call('save')
            ->assertHasErrors(["data.blocks.{$field}"]);
    }
    $hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => 'hr_staff']);
    actingInAdminTenant($this, $hr, $this->company);
    Livewire::test(DocumentTemplates::class)->assertOk()->call('save')->assertForbidden();
    expect(DocumentTemplate::query()->count())->toBe(0);
});

test('letterhead versions validate uploads and retain image hashes and audit history', function (): void {
    $service = app(LetterheadService::class);
    $fields = ['name' => 'Example Company', 'address_lines' => 'Demo Road', 'phone' => '000',
        'email' => 'contact@example.test', 'footer_text' => 'CONFIDENTIAL', 'header_mode' => 'logo_text'];
    expect(fn () => $service->saveVersion($this->company, 'en', $fields, $this->admin,
        UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml')))->toThrow(ValidationException::class);
    expect(fn () => $service->saveVersion($this->company, 'en', $fields, $this->admin,
        UploadedFile::fake()->image('large.png')->size(2049)))->toThrow(ValidationException::class);
    $version = $service->saveVersion($this->company, 'en', $fields, $this->admin,
        UploadedFile::fake()->image('logo.png'));
    expect($version->version)->toBe(2)->and($version->logo_sha256)->toHaveLength(64)
        ->and($version->needs_review)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'company_letterhead.version_created')->exists())->toBeTrue();
});

test('preview iframe is sandboxed and English fields fall back when absent', function (): void {
    $this->get(DocumentTemplates::getUrl(panel: 'admin', tenant: $this->company))
        ->assertOk()->assertSee('sandbox')->assertDontSee('allow-scripts');
    $case = Document::factory()->create(['created_by' => $this->admin->id]);
    $employee = $case->disciplinaryCase->employee;
    $employee->update(['name' => 'رياض علي', 'name_en' => 'Riyad Ali', 'position' => 'مشغل',
        'position_en' => 'Operator', 'gender' => 'male']);
    foreach (['ar', 'en'] as $language) {
        $case->contents()->create(['company_id' => $this->company->id, 'language_code' => $language,
            'body' => 'Text', 'is_authoritative' => $language === 'ar']);
    }
    $html = app(DocumentRenderer::class)->render($case, true);
    expect($html)->toContain('Dear Mr Riyad', 'Operator');
    $employee->update(['name_en' => null, 'position_en' => null]);
    $html = app(DocumentRenderer::class)->render($case, true);
    expect($html)->toContain('Dear Mr رياض', 'مشغل');
});

test('English attendee and General Manager names use optional English renderings', function (): void {
    $case = Document::factory()->create(['document_type' => 'decision_letter', 'penalty_type' => 'warning',
        'created_by' => $this->admin->id]);
    $disciplinary = $case->disciplinaryCase;
    $disciplinary->update(['defense_letter_issued_at' => now()->subDays(3), 'committee_session_held_at' => now(),
        'defense_acceptance' => 'rejected', 'outcome' => 'warning']);
    $mandate = CommitteeMandate::factory()->create(['effective_from' => now()->subDays(10)]);
    foreach (['legal', 'hr', 'labor_union'] as $role) {
        $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id,
            'role_type' => $role, 'employee_id' => null, 'person_name' => 'اسم عربي', 'person_name_en' => 'English Attendee']);
        CommitteeSessionAttendance::factory()->create(['case_id' => $disciplinary->id,
            'committee_pool_member_id' => $member->id, 'is_chair' => $role === 'legal']);
    }
    Employee::factory()->create(['is_general_manager' => true, 'name' => 'مدير عربي', 'name_en' => 'English Manager']);
    foreach (['ar', 'en'] as $language) {
        $case->contents()->create(['company_id' => $this->company->id, 'language_code' => $language,
            'body' => 'Reasons', 'is_authoritative' => $language === 'ar']);
    }
    $html = app(DocumentRenderer::class)->render($case, true);
    expect($html)->toContain('English Attendee', 'English Manager');
    CommitteePoolMember::query()->where('person_name_en', 'English Attendee')->update(['person_name_en' => null]);
    Employee::query()->where('is_general_manager', true)->firstOrFail()->update(['name_en' => null]);
    $fallback = app(DocumentRenderer::class)->render($case, true);
    $englishPage = substr($fallback, strpos($fallback, 'lang="en"'));
    expect($englishPage)->toContain('اسم عربي', 'مدير عربي');
});
