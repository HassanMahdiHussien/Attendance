<?php

use App\Documents\PdfGenerator;
use App\Jobs\GenerateDocumentPdf;
use App\Models\Company;
use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentIssuer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

test('real Chrome renders embedded fonts and a bilingual A4 PDF from frozen HTML', function (): void {
    expect(is_file(config('displanery.pdf.chrome_path')))->toBeTrue();
    Queue::fake();
    $company = Company::factory()->create(['name' => 'Bilingual Company']);
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $document = Document::factory()->create(['created_by' => $actor->id]);
    foreach (['ar' => 'محتوى قصير', 'en' => 'Short body'] as $language => $body) {
        $document->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => $body, 'is_authoritative' => $language === 'ar']);
    }
    $issued = app(DocumentIssuer::class)->issue($document, $actor);
    $fontHtml = app(PdfGenerator::class)->htmlWithFonts($issued->rendered_html);
    expect($fontHtml)->toContain("font-family:'Displanery Naskh';font-weight:100 900", 'data:font/ttf;base64,');
    expect(strpos($issued->rendered_html, 'lang="ar"'))->toBeLessThan(strpos($issued->rendered_html, 'lang="en"'));
    expect(substr_count($issued->rendered_html, 'CONFIDENTIAL'))->toBe(2);
    try {
        $job = new GenerateDocumentPdf($issued->id, $company->id);
        $job->handle(app(PdfGenerator::class));
        $ready = $issued->fresh();
        $path = Storage::disk('local')->path($ready->pdf_file_path);
        $pdf = file_get_contents($path);
        expect($ready->pdf_status)->toBe('ready')
            ->and(str_starts_with($pdf, '%PDF'))->toBeTrue()
            ->and(hash('sha256', $pdf))->toBe($ready->pdf_sha256)
            ->and((bool) preg_match('/\/Type\s*\/Page\b/', $pdf))->toBeTrue()
            ->and(str_contains($pdf, '/FontFile2'))->toBeTrue();
        $job->handle(app(PdfGenerator::class));
        expect(hash_file('sha256', $path))->toBe($ready->pdf_sha256);
        $this->actingAs($actor)->get(route('documents.pdf', $ready))->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        file_put_contents($path, 'tamper', FILE_APPEND);
        $this->actingAs($actor)->get(route('documents.pdf', $ready))->assertStatus(409);
    } finally {
        Storage::disk('local')->deleteDirectory("documents/{$company->id}");
    }
});

test('Chrome failure marks the issued PDF failed and retry restores pending status', function (): void {
    Queue::fake();
    Notification::fake();
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $document = Document::factory()->create(['created_by' => $actor->id]);
    foreach (['ar', 'en'] as $language) {
        $document->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'Short body', 'is_authoritative' => $language === 'ar']);
    }
    $issued = app(DocumentIssuer::class)->issue($document, $actor);
    $original = config('displanery.pdf.chrome_path');
    config(['displanery.pdf.chrome_path' => '/not/a/chrome']);
    try {
        $job = new GenerateDocumentPdf($issued->id, $company->id);
        try {
            $job->handle(app(PdfGenerator::class));
            $this->fail('Missing Chrome must fail.');
        } catch (RuntimeException $error) {
            $job->failed($error);
        }
        expect($issued->fresh()->pdf_status)->toBe('failed');
        Artisan::call('documents:retry-pdf', ['id' => $issued->id]);
        expect($issued->fresh()->pdf_status)->toBe('pending');
        Bus::assertDispatched(GenerateDocumentPdf::class, fn ($job) => $job->documentId === $issued->id);
    } finally {
        config(['displanery.pdf.chrome_path' => $original]);
    }

    // With Chrome back, the retried job completes and the PDF is ready exactly once.
    (new GenerateDocumentPdf($issued->id, $company->id))->handle(app(PdfGenerator::class));
    $ready = $issued->fresh();
    expect($ready->pdf_status)->toBe('ready')
        ->and($ready->pdf_sha256)->toHaveLength(64)
        ->and(Storage::disk('local')->exists($ready->pdf_file_path))->toBeTrue();
});

test('draft preview is authorised and pending PDF downloads return the Arabic 409 response', function (): void {
    Queue::fake();
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $document = Document::factory()->create(['created_by' => $actor->id]);
    foreach (['ar', 'en'] as $language) {
        $document->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'Short body', 'is_authoritative' => $language === 'ar']);
    }
    $this->actingAs($actor)->get(route('documents.preview', $document))->assertOk()->assertSee('DRAFT');
    $issued = app(DocumentIssuer::class)->issue($document, $actor);
    $this->actingAs($actor)->get(route('documents.pdf', $issued))->assertStatus(409)->assertSee('The PDF is being created');
    $outsider = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $this->actingAs($outsider)->get(route('documents.pdf', $issued))->assertForbidden();
});

test('a long bilingual body flows beyond one page per language', function (): void {
    expect(is_file(config('displanery.pdf.chrome_path')))->toBeTrue();
    Queue::fake();
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $document = Document::factory()->create(['created_by' => $actor->id]);
    foreach (['ar' => 'هذه فقرة طويلة للفحص.', 'en' => 'This is a long test paragraph.'] as $language => $line) {
        $document->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => implode("\n\n", array_fill(0, 100, $line)),
            'is_authoritative' => $language === 'ar']);
    }
    $issued = app(DocumentIssuer::class)->issue($document, $actor);
    $pdf = app(PdfGenerator::class)->generate($issued->rendered_html);
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $matches);
    expect(count($matches[0]))->toBeGreaterThan(2);
});
