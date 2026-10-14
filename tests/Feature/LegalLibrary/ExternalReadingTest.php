<?php

use App\Enums\ImportMode;
use App\Jobs\BuildLegalUnits;
use App\Jobs\ReadLegalPageText;
use App\Jobs\ReadLegalPageWithAi;
use App\Jobs\RenderLegalPages;
use App\Jobs\SubmitLegalReadingBatch;
use App\LegalLibrary\ArticleSplitter;
use App\LegalLibrary\DocxText;
use App\LegalLibrary\ExternalReadings;
use App\LegalLibrary\LawImportService;
use App\LegalLibrary\MismatchResolver;
use App\LegalLibrary\PdfPages;
use App\LegalLibrary\UnitBuilder;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('two supplied readings queue no Claude page calls', function (): void {
    $source = base_path('tests/fixtures/legal/text-law.pdf');
    if (! is_file($source)) {
        $this->markTestSkipped('Legal PDF fixture is unavailable.');
    }
    Bus::fake([RenderLegalPages::class, ReadLegalPageText::class, ReadLegalPageWithAi::class,
        SubmitLegalReadingBatch::class, BuildLegalUnits::class]);
    app(TenantContext::class)->runAcrossAllCompanies(function () use ($source): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create(['kind' => 'statute']);
        $jsonPath = tempnam(sys_get_temp_dir(), 'pages-');
        $textPath = tempnam(sys_get_temp_dir(), 'secondary-');
        try {
            file_put_contents($jsonPath, json_encode(['pages' => ['1' => 'المادة 1', '2' => 'المادة 2']], JSON_UNESCAPED_UNICODE));
            file_put_contents($textPath, "المادة 1\nنص ثانوي\nالمادة 2\nنص ثانوي");
            $run = app(LawImportService::class)->start($law, $actor, $source, 1, 2, ImportMode::Auto,
                'batch', new UploadedFile($jsonPath, 'pages.json', 'application/json', null, true),
                new UploadedFile($textPath, 'secondary.txt', 'text/plain', null, true));
            (new RenderLegalPages($run->id, 'pdf'))->handle(app(PdfPages::class), app(DocxText::class),
                app(ArticleSplitter::class), app(AuditLogger::class));
            expect($run->fresh()->state)->toBe('splitting')
                ->and($run->pages()->where('text_method', 'transcription')->count())->toBe(2)
                ->and($run->pages()->where('ai_source', 'secondary_text')->count())->toBe(2);
            Bus::assertNotDispatched(ReadLegalPageWithAi::class);
            Bus::assertNotDispatched(SubmitLegalReadingBatch::class);
        } finally {
            unlink($jsonPath);
            unlink($textPath);
        }
    });
});

test('transcription upload validates page numbers and records the uploader without an AI call', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create(['kind' => 'statute']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'gazette.pdf',
            'source_sha256' => str_repeat('a', 64), 'page_from' => 1, 'page_to' => 2,
            'mode' => 'auto', 'state' => 'queued', 'started_by' => $actor->id, 'started_at' => now()]);
        $file = tempnam(sys_get_temp_dir(), 'legal-pages-');
        try {
            file_put_contents($file, json_encode(['pages' => ['3' => 'نص خاطئ']], JSON_UNESCAPED_UNICODE));
            $upload = new UploadedFile($file, 'pages.json', 'application/json', null, true);
            expect(fn () => app(ExternalReadings::class)->attach($run, $actor, $upload, null))
                ->toThrow(ValidationException::class);
            file_put_contents($file, json_encode(['pages' => ['1' => 'المادة 1']], JSON_UNESCAPED_UNICODE));
            $upload = new UploadedFile($file, 'pages.json', 'application/json', null, true);
            app(ExternalReadings::class)->attach($run, $actor, $upload, null, 'Independent page reading');
            expect($run->fresh()->transcription_uploaded_by)->toBe($actor->id)
                ->and(app(ExternalReadings::class)->pageTexts($run->fresh()))->toBe([1 => 'المادة 1']);
        } finally {
            unlink($file);
        }
    });
});

test('a repaired independent secondary text verifies only matching gazette units', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create(['kind' => 'statute']);
        $secondary = "املادة 1\nيسري هذا القانون.\nاملادة 2\nنص مختلف.\nاملادة 3\nنص ثانوي فقط.";
        Storage::disk('local')->put('legal-library/secondary.txt', $secondary);
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'gazette.pdf',
            'source_sha256' => str_repeat('a', 64), 'page_from' => 1, 'page_to' => 1,
            'mode' => 'auto', 'state' => 'splitting', 'started_by' => $actor->id,
            'started_at' => now(), 'secondary_source_path' => 'legal-library/secondary.txt',
            'secondary_source_sha256' => hash('sha256', $secondary)]);
        LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'gazette.png', 'image_sha256' => str_repeat('b', 64),
            'text_status' => 'done', 'text_method' => 'transcription',
            'text' => "المادة 1\nيسري هذا القانون.\nالمادة 2\nنص رسمي.",
            'ai_status' => 'done', 'ai_source' => 'secondary_text']);
        app(UnitBuilder::class)->build($run);
        $first = LegalUnit::where('law_id', $law->id)->where('article_number', '1')->firstOrFail();
        $second = LegalUnit::where('law_id', $law->id)->where('article_number', '2')->firstOrFail();
        $third = LegalUnit::where('law_id', $law->id)->where('article_number', '3')->firstOrFail();
        expect($first->status->value)->toBe('verified_auto')
            ->and($first->official_text)->toContain('يسري هذا القانون')
            ->and($first->source_page)->toBe(1)
            ->and($first->tags['reading_b_source'])->toBe('secondary_text')
            ->and($second->status->value)->toBe('mismatch')
            ->and($third->status->value)->toBe('mismatch')
            ->and($third->source_page)->toBeNull();
        expect(fn () => app(MismatchResolver::class)->resolve($third, $actor, 'b'))
            ->toThrow(ValidationException::class);
    });
});
