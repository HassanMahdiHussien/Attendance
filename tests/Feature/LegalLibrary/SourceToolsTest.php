<?php

use App\Jobs\RenderLegalPages;
use App\LegalLibrary\ArticleSplitter;
use App\LegalLibrary\DocxText;
use App\LegalLibrary\PdfPages;
use App\LegalLibrary\TextLayerProbe;
use App\Models\Company;
use App\Models\Law;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

test('text fixture has a usable text layer and image fixture needs OCR', function (): void {
    $text = base_path('tests/fixtures/legal/text-law.pdf');
    $image = base_path('tests/fixtures/legal/image-law.pdf');
    if (! is_file($text) || ! is_file($image)) {
        $this->markTestSkipped('Run tests/fixtures/legal/generate.php inside the app container first.');
    }
    $pdf = new PdfPages;
    expect($pdf->count($text))->toBe(2);
    expect($pdf->count($image))->toBe(2);
    expect((new TextLayerProbe)->usable($pdf->text($text, 1)))->toBeTrue();
    expect((new TextLayerProbe)->usable($pdf->text($image, 1)))->toBeFalse();
    $path = sys_get_temp_dir().'/legal-test-page-'.uniqid();
    try {
        $png = $pdf->render($image, 1, $path);
        expect(preg_match('/(?:المادة|مادة)\s*[-(]*\s*[١1]/u', $pdf->ocr($png)))->toBe(1);
    } finally {
        @unlink($path.'.png');
    }
});

test('Word regulation import creates verified text units without page images', function (): void {
    $path = base_path('tests/fixtures/legal/regulation.docx');
    if (! is_file($path)) {
        $this->markTestSkipped('Run tests/fixtures/legal/generate.php inside the app container first.');
    }
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company, $path): void {
        $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
        $law = Law::factory()->create(['company_id' => $company->id, 'kind' => 'internal_regulation']);
        Storage::disk('local')->put('legal-library/sources/test.docx', file_get_contents($path));
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'legal-library/sources/test.docx',
            'source_sha256' => hash_file('sha256', $path), 'mode' => 'auto', 'state' => 'queued',
            'started_by' => $actor->id, 'started_at' => now()]);
        (new RenderLegalPages($run->id, 'docx'))->handle(app(PdfPages::class), app(DocxText::class),
            app(ArticleSplitter::class), app(AuditLogger::class));
        expect($run->fresh()->state)->toBe('completed');
        expect($run->pages()->count())->toBe(0);
        expect(LegalUnit::where('law_id', $law->id)->where('status', 'verified_text')->count())->toBe(3);
    });
});

test('Word source retains inserted text and drops deleted text', function (): void {
    $path = base_path('tests/fixtures/legal/regulation.docx');
    if (! is_file($path)) {
        $this->markTestSkipped('Run tests/fixtures/legal/generate.php inside the app container first.');
    }
    $text = (new DocxText)->read($path);
    expect($text)->toContain('نص مضاف')->not->toContain('نص محذوف');
});
