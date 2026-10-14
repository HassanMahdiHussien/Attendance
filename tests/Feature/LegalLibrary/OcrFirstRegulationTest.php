<?php

use App\Jobs\BuildLegalUnits;
use App\Jobs\ReadLegalPageText;
use App\Jobs\ReadLegalPageWithAi;
use App\LegalLibrary\BatchClient;
use App\LegalLibrary\BatchPageReader;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\PdfPages;
use App\LegalLibrary\RegulationApprovals;
use App\LegalLibrary\TextLayerProbe;
use App\LegalLibrary\UnitBuilder;
use App\LegalLibrary\UnitCorrection;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\Fakes\FakeClaudeClient;

test('Tesseract TSV uses valid word confidences and preserves lines', function (): void {
    $tsv = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tconf\ttext\n"
        ."5\t1\t1\t1\t1\t1\t90\tالمادة\n"
        ."5\t1\t1\t1\t1\t2\t80\t1\n"
        ."5\t1\t1\t1\t2\t1\t-1\t\n"
        ."5\t1\t1\t1\t2\t2\t85\tنص\n";
    expect((new PdfPages)->parseOcrTsv($tsv))->toBe(['text' => "المادة 1\nنص", 'confidence' => 85.0]);
    expect((new PdfPages)->parseOcrTsv('invalid'))->toBe(['text' => '', 'confidence' => null]);
});

test('confident regulation OCR skips AI and requires individual lawyer approval', function (): void {
    Bus::fake([BuildLegalUnits::class, ReadLegalPageWithAi::class]);
    $fake = new FakeClaudeClient;
    app()->instance(BatchClient::class, $fake);
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
        Permission::findOrCreate('review_internal_regulation', 'web');
        $actor->givePermissionTo('review_internal_regulation');
        $law = Law::factory()->create(['company_id' => $company->id, 'kind' => 'internal_regulation']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'ocr', 'reading_mode' => 'now',
            'state' => 'reading', 'started_by' => $actor->id, 'started_at' => now()]);
        $page = LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'fixture.png', 'image_sha256' => str_repeat('b', 64)]);
        $pdf = new class extends PdfPages
        {
            public function ocrWithConfidence(string $png): array
            {
                return ['text' => "المادة 1\nنص واضح وصحيح", 'confidence' => 92.5];
            }
        };
        (new ReadLegalPageText($page->id, $company->id))->handle($pdf, app(TextLayerProbe::class), app(ImportProgress::class));
        expect($page->fresh()->ai_status)->toBe('skipped')
            ->and((float) $page->fresh()->ocr_confidence)->toBe(92.5);
        Bus::assertNotDispatched(ReadLegalPageWithAi::class);
        app(UnitBuilder::class)->build($run->fresh());
        $unit = LegalUnit::where('law_id', $law->id)->firstOrFail();
        expect($unit->status->value)->toBe('verified_single')
            ->and($unit->extraction_b)->toBeNull()
            ->and($run->fresh()->counts['local_pages'])->toBe(1)
            ->and($run->fresh()->counts['ai_pages'])->toBe(0);
        expect(app(RegulationApprovals::class)->approveAllVerified($law, $actor))->toBe(0);
        $statute = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
        $statute->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
        $case = DisciplinaryCase::factory()->create(['law_version_id' => $statute->id]);
        expect(LegalUnit::citableFor($case)->whereKey($unit->id)->exists())->toBeFalse();
        app(RegulationApprovals::class)->approve($unit, $actor);
        expect(LegalUnit::citableFor($case)->whereKey($unit->id)->exists())->toBeTrue();
        expect(AuditLog::where('action', 'legal_unit.approved')->where('entity_id', $unit->id)
            ->latest('id')->firstOrFail()->metadata['method'])->toBe('single_reading');
        expect(fn () => DB::table('legal_units')->where('id', $unit->id)
            ->update(['official_text' => 'changed']))->toThrow(QueryException::class);
        $replacement = app(UnitCorrection::class)->correct($unit, $actor, 'نص مصحح', 'Checked source page');
        expect($replacement->previous_version_id)->toBe($unit->id)
            ->and(LegalUnit::citableFor($case)->whereKey($unit->id)->exists())->toBeFalse()
            ->and(LegalUnit::citableFor($case)->whereKey($replacement->id)->exists())->toBeFalse();
    });
    expect($fake->requests)->toBe([])->and($fake->batchRequests)->toBe([]);
});

test('regulation batch waits for local readings and sends no request for skipped pages', function (): void {
    Bus::fake([BuildLegalUnits::class]);
    $fake = new FakeClaudeClient;
    app()->instance(BatchClient::class, $fake);
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
        $law = Law::factory()->create(['company_id' => $company->id, 'kind' => 'internal_regulation']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'ocr', 'reading_mode' => 'batch',
            'batch_state' => 'submitting', 'state' => 'reading', 'started_by' => $actor->id, 'started_at' => now()]);
        $page = LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'fixture.png', 'image_sha256' => str_repeat('b', 64)]);
        app(BatchPageReader::class)->submit($run);
        expect($run->fresh()->batch_id)->toBeNull()->and($page->fresh()->ai_status)->toBe('pending');
        $page->update(['text_status' => 'done', 'text' => "المادة 1\nنص واضح وصحيح", 'ai_status' => 'skipped']);
        app(BatchPageReader::class)->submit($run->fresh());
        expect($run->fresh()->state)->toBe('splitting')->and($run->fresh()->batch_id)->toBeNull();
    });
    expect($fake->batchRequests)->toBe([]);
});

test('usable regulation text layer skips OCR and AI', function (): void {
    Bus::fake([BuildLegalUnits::class, ReadLegalPageWithAi::class]);
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
        $law = Law::factory()->create(['company_id' => $company->id, 'kind' => 'internal_regulation']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'auto', 'reading_mode' => 'now',
            'state' => 'reading', 'started_by' => $actor->id, 'started_at' => now()]);
        $page = LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'fixture.png', 'image_sha256' => str_repeat('b', 64)]);
        $pdf = new class extends PdfPages
        {
            public function text(string $pdf, int $page): string
            {
                return "المادة 1\n".str_repeat('هذا نص النظام الداخلي واضح ومكتمل. ', 24);
            }

            public function ocrWithConfidence(string $png): array
            {
                throw new RuntimeException('OCR must not run for a usable layer.');
            }
        };
        (new ReadLegalPageText($page->id, $company->id))->handle($pdf, app(TextLayerProbe::class), app(ImportProgress::class));
        expect($page->fresh()->text_method)->toBe('text')
            ->and($page->fresh()->ai_status)->toBe('skipped')
            ->and($page->fresh()->ocr_confidence)->toBeNull();
        Bus::assertNotDispatched(ReadLegalPageWithAi::class);
    });
});

test('low confidence regulation OCR queues AI and mixed articles require review', function (): void {
    Bus::fake([BuildLegalUnits::class, ReadLegalPageWithAi::class]);
    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
        $law = Law::factory()->create(['company_id' => $company->id, 'kind' => 'internal_regulation']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'ocr', 'reading_mode' => 'now',
            'state' => 'reading', 'started_by' => $actor->id, 'started_at' => now()]);
        $first = LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'first.png', 'image_sha256' => str_repeat('b', 64),
            'text_status' => 'done', 'text_method' => 'ocr', 'text' => "المادة 1\nنص واضح يبدأ هنا",
            'ai_status' => 'skipped']);
        $second = LegalImportPage::create(['run_id' => $run->id, 'page_number' => 2,
            'image_path' => 'second.png', 'image_sha256' => str_repeat('c', 64)]);
        $pdf = new class extends PdfPages
        {
            public function ocrWithConfidence(string $png): array
            {
                return ['text' => 'ويستمر النص هنا بوضوح', 'confidence' => 60.0];
            }
        };
        (new ReadLegalPageText($second->id, $company->id))->handle($pdf, app(TextLayerProbe::class), app(ImportProgress::class));
        expect($second->fresh()->ai_status)->toBe('pending')
            ->and((float) $second->fresh()->ocr_confidence)->toBe(60.0);
        Bus::assertDispatched(ReadLegalPageWithAi::class, fn ($job) => $job->pageId === $second->id);
        $second->update(['ai_status' => 'done', 'ai_text' => 'ويستمر النص هنا بوضوح', 'ai_source' => 'api']);
        app(ImportProgress::class)->evaluate($run);
        app(UnitBuilder::class)->build($run->fresh());
        $unit = LegalUnit::where('law_id', $law->id)->firstOrFail();
        expect($unit->status->value)->toBe('mismatch')
            ->and($unit->tags['mismatch_reason'])->toBe('partial_single_reading');
    });
});
