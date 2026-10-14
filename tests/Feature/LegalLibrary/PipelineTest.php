<?php

use App\Enums\ImportMode;
use App\Jobs\BuildLegalUnits;
use App\Jobs\ReadLegalPageText;
use App\Jobs\ReadLegalPageWithAi;
use App\Jobs\RenderLegalPages;
use App\LegalLibrary\ArticleSplitter;
use App\LegalLibrary\DocxText;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\LawImportService;
use App\LegalLibrary\PageReader;
use App\LegalLibrary\PdfPages;
use App\LegalLibrary\TextLayerProbe;
use App\LegalLibrary\UnitBuilder;
use App\Models\Law;
use App\Models\LegalUnit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Validation\ValidationException;
use Tests\Fakes\FakePageReader;

test('PDF imports keep both readings and split only after all pages finish', function (string $file): void {
    $path = base_path('tests/fixtures/legal/'.$file);
    if (! is_file($path)) {
        $this->markTestSkipped('Run tests/fixtures/legal/generate.php inside the app container first.');
    }
    Bus::fake([RenderLegalPages::class, ReadLegalPageText::class, ReadLegalPageWithAi::class, BuildLegalUnits::class]);
    $fake = new FakePageReader;
    $fake->pages = [
        1 => 'الوقائع العراقية — العدد 0000
المادة 1
تسري أحكام هذا القانون على المؤسسة.
المادة (2)
أولاً- تحفظ السجلات في موضع آمن.
ثانياً- تتبع الإدارة الخطوات الآتية:
أ- تدقق الطلبات.
ب- تحفظ النسخ.
المادة الثالثة
تبدأ هذه المادة في الصفحة الأولى. '
            .str_repeat('وتحفظ نسخة من السجل في موضع آمن. ', 5).'
1',
        2 => 'الوقائع العراقية — العدد 0000
وتستمر في الصفحة الثانية مع بيان موجز. '.str_repeat('وتراجع الإدارة النسخة عند الحاجة. ', 6)
            .'
المادة -4-
ينشر هذا القانون ويعمل به من تاريخ نشره.
2',
    ];
    app()->instance(PageReader::class, $fake);
    $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
    app(TenantContext::class)->runAcrossAllCompanies(function () use ($path, $actor, $file, $fake): void {
        $law = Law::factory()->create(['kind' => 'statute', 'category' => 'labor']);
        $service = app(LawImportService::class);
        $run = $service->start($law, $actor, new UploadedFile($path, $file, 'application/pdf', null, true), 1, 2, ImportMode::Auto, 'now');
        expect(fn () => $service->start($law, $actor, $path, 1, 2, ImportMode::Auto))->toThrow(ValidationException::class);
        (new RenderLegalPages($run->id, 'pdf'))->handle(app(PdfPages::class),
            app(DocxText::class), app(ArticleSplitter::class), app(AuditLogger::class));
        $run->refresh();
        expect($run->error)->toBeNull();
        expect($run->pages()->count())->toBe(2);
        foreach ($run->pages as $page) {
            (new ReadLegalPageText($page->id, null))->handle(app(PdfPages::class),
                app(TextLayerProbe::class), app(ImportProgress::class));
            (new ReadLegalPageWithAi($page->id, null))->handle($fake, app(ImportProgress::class));
        }
        expect($run->fresh()->state)->toBe('splitting');
        (new BuildLegalUnits($run->id))->handle(app(UnitBuilder::class));
        expect($run->fresh()->state)->toBe('completed');
        expect(LegalUnit::where('law_id', $law->id)->where('article_number', '1')->exists())->toBeTrue();
        $method = $file === 'text-law.pdf' ? 'text' : 'ocr';
        expect($run->pages()->where('text_method', $method)->count())->toBe(2);
        // Article 1 and the 2/أولاً clause read identically in both readings; article 2 as a whole
        // carries the ثانياً text, which OCR misreads (الآنية) only on the image path.
        $status = fn (string $article, ?string $clause) => LegalUnit::where('law_id', $law->id)
            ->where('article_number', $article)->where('clause_path', $clause)->value('status')?->value;
        if ($file === 'spaced-law.pdf') {
            // The spaced text layer is rejected and OCR'd; OCR loses clause ب on this page, so article 2
            // must go to review while the untouched article 4 still verifies.
            expect($status('2', '2/أولاً'))->toBe('mismatch');
            expect($status('4', null))->toBe('verified_auto');
        } else {
            expect($status('1', null))->toBe('verified_auto');
            expect($status('2', '2/أولاً'))->toBe('verified_auto');
        }
        if ($file === 'text-law.pdf') {
            expect(LegalUnit::where('law_id', $law->id)->where('status', 'mismatch')->count())->toBe(0);
        }
        expect(LegalUnit::where('law_id', $law->id)->pluck('article_number')->unique()->sort()->values()->all())
            ->toBe(['1', '2', '3', '4']);
    });
})->with(['text-law.pdf', 'image-law.pdf', 'spaced-law.pdf']);
