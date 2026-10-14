<?php

use App\Jobs\BuildLegalUnits;
use App\Jobs\ReadLegalPageText;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\PdfPages;
use App\LegalLibrary\TextLayerProbe;
use App\LegalLibrary\TextLayerRepair;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;

test('mirrored brackets are swapped on real text layer lines without changing other lines', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $law = Law::factory()->create(['text_layer_fixes' => ['mirrored_brackets']]);
        $source = ")ثامنا( أحكام القانون\n)‪ (4‬ من المادة\nسطر بلا أقواس";
        $result = app(TextLayerRepair::class)->repair($source, $law);

        expect($result['text'])->toBe("(ثامنا) أحكام القانون\n(‪ )4‬ من المادة\nسطر بلا أقواس")
            ->and($result['counts']['mirrored_brackets'])->toBeGreaterThan(0);
    });
});

test('kaf repair uses only current verified words from other statute laws', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $law = Law::factory()->create(['text_layer_fixes' => ['kaf_from_alef_madda']]);
        $other = Law::factory()->create();
        LegalUnit::factory()->create(['law_id' => $other->id, 'status' => 'verified_human',
            'official_text' => 'وكان، كأنه؛ الآتية. آلة كآ آك']);
        LegalUnit::factory()->create(['law_id' => $law->id, 'status' => 'verified_human',
            'official_text' => 'كذلك']);
        $result = app(TextLayerRepair::class)->repair('وآان آأنه الآتية آلة آآ آذلك آغريب', $law);

        expect($result['text'])->toBe('وكان كأنه الآتية آلة آآ آذلك آغريب')
            ->and($result['counts']['kaf_from_alef_madda'])->toBe(2);
    });
});

test('kaf repair leaves words unchanged when other statutes have no verified vocabulary', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $law = Law::factory()->create(['text_layer_fixes' => ['kaf_from_alef_madda']]);
        LegalUnit::factory()->create(['law_id' => $law->id, 'official_text' => 'وكان']);
        $result = app(TextLayerRepair::class)->repair('وآان', $law);

        expect($result['text'])->toBe('وآان')->and($result['counts']['kaf_from_alef_madda'])->toBe(0);
    });
});

test('page reading repairs only configured text layers and records per-page counts', function (): void {
    Bus::fake([BuildLegalUnits::class]);
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $vocabularyLaw = Law::factory()->create();
        LegalUnit::factory()->create(['law_id' => $vocabularyLaw->id, 'official_text' => 'وكان']);
        $pdf = new class extends PdfPages
        {
            public function text(string $pdf, int $page): string
            {
                return ')ثامنا( وآان';
            }

            public function ocr(string $png): string
            {
                return ')ثامنا( وآان';
            }
        };

        foreach ([['text', ['mirrored_brackets', 'kaf_from_alef_madda'], '(ثامنا) وكان'],
            ['ocr', ['mirrored_brackets', 'kaf_from_alef_madda'], ')ثامنا( وآان'],
            ['text', null, ')ثامنا( وآان']] as [$mode, $fixes, $expected]) {
            $law = Law::factory()->create(['text_layer_fixes' => $fixes]);
            $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
                'source_sha256' => str_repeat('a', 64), 'mode' => $mode, 'reading_mode' => 'now',
                'state' => 'reading', 'started_by' => $actor->id, 'started_at' => now()]);
            $page = LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
                'image_path' => 'fixture.png', 'image_sha256' => str_repeat('b', 64)]);

            (new ReadLegalPageText($page->id, null))->handle($pdf, app(TextLayerProbe::class), app(ImportProgress::class));

            expect($page->fresh()->text)->toBe($expected)
                ->and($page->fresh()->text_method)->toBe($mode)
                ->and($page->fresh()->text_repairs)->toBe($mode === 'text' && $fixes !== null
                    ? ['mirrored_brackets' => 1, 'kaf_from_alef_madda' => 1] : null);
        }
    });
});
