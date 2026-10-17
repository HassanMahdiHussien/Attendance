<?php

use App\Enums\Jurisdiction;
use App\LegalLibrary\LegalLibraryArchive;
use App\LegalLibrary\PdfPages;
use App\LegalLibrary\TextNormalizer;
use App\Models\Company;
use App\Models\Law;
use App\Models\LawJurisdiction;
use App\Models\LegalUnit;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;

test('a law with units still in review exports them as mismatches that stay uncitable after import', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $path = 'docs/laws/labor-law-71-1987.pdf';
        $law = Law::factory()->create(['kind' => 'statute', 'number' => '71', 'year' => 1987,
            'category' => 'labor', 'source_file_path' => $path,
            'source_sha256' => hash_file('sha256', base_path($path))]);
        LawJurisdiction::create(['law_id' => $law->id, 'jurisdiction' => Jurisdiction::KurdistanRegion]);
        LegalUnit::create(['law_id' => $law->id, 'company_id' => null, 'version' => 1,
            'article_number' => '1', 'clause_path' => null, 'official_text' => 'المادة 1 نص',
            'source_page' => 1, 'extraction_a' => 'المادة 1 نص', 'extraction_b' => 'المادة 1 نص',
            'status' => 'verified_auto', 'verified_at' => now()]);
        LegalUnit::create(['law_id' => $law->id, 'company_id' => null, 'version' => 1,
            'article_number' => '2', 'clause_path' => null, 'official_text' => 'المادة 2 نص',
            'source_page' => 1, 'extraction_a' => 'المادة 2 نص', 'extraction_b' => 'المادة 2 نص آخر',
            'status' => 'mismatch']);
        // The same article printed twice (original and amended wording) on one page.
        LegalUnit::create(['law_id' => $law->id, 'company_id' => null, 'version' => 1,
            'article_number' => '2', 'clause_path' => null, 'official_text' => 'المادة 2 نص معدل',
            'source_page' => 1, 'extraction_a' => 'المادة 2 نص معدل', 'extraction_b' => 'المادة 2 نص معدل آخر',
            'status' => 'mismatch']);
        $directory = Storage::disk('local')->path('archive-pending-test');
        $archive = new LegalLibraryArchive(app(PdfPages::class), app(TextNormalizer::class),
            app(AuditLogger::class), $directory);
        expect($archive->export('71'))->toBe(1);
        $payload = json_decode(file_get_contents($directory.'/law-71-1987-labor.json'), true);
        expect(collect($payload['units'])->pluck('status')->sort()->values()->all())->toBe(['mismatch', 'mismatch', 'verified_auto'])
            ->and($archive->import())->toBe(1)
            ->and(LegalUnit::where('law_id', $law->id)->count())->toBe(3);
        $payload['law']['number'] = '172';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
        file_put_contents($directory.'/law-172-1987-labor.json', $json);
        file_put_contents($directory.'/manifest.json', json_encode(['schema_version' => 1,
            'files' => ['law-172-1987-labor.json' => hash('sha256', $json)]]));
        expect($archive->import())->toBe(1);
        $imported = Law::where('number', '172')->firstOrFail();
        expect(LegalUnit::where('law_id', $imported->id)->where('article_number', '2')->pluck('status')->map->value->all())->toBe(['mismatch', 'mismatch'])
            ->and($archive->import())->toBe(1)
            ->and(LegalUnit::where('law_id', $imported->id)->count())->toBe(3);

        LegalUnit::where('law_id', $law->id)->where('article_number', '2')->update(['status' => 'verified_single']);
        expect(fn () => $archive->export('71'))->toThrow(RuntimeException::class, 'unverified current units');
    });
});

test('verified law export is stable, import is idempotent and a modified file is rejected', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $path = 'docs/laws/labor-law-71-1987.pdf';
        $law = Law::factory()->create(['kind' => 'statute', 'number' => '71', 'year' => 1987,
            'category' => 'labor', 'source_file_path' => $path,
            'source_sha256' => hash_file('sha256', base_path($path))]);
        LawJurisdiction::create(['law_id' => $law->id, 'jurisdiction' => Jurisdiction::KurdistanRegion]);
        LegalUnit::create(['law_id' => $law->id, 'company_id' => null, 'version' => 1,
            'article_number' => '1', 'clause_path' => null, 'official_text' => 'المادة 1 نص',
            'source_page' => 1, 'extraction_a' => 'المادة 1 نص', 'extraction_b' => 'المادة 1 نص',
            'status' => 'verified_auto', 'verified_at' => now()]);
        $company = Company::factory()->create();
        $regulation = Law::factory()->create(['company_id' => $company->id,
            'kind' => 'internal_regulation', 'category' => 'regulation']);
        LegalUnit::create(['law_id' => $regulation->id, 'company_id' => $company->id,
            'version' => 1, 'article_number' => '1', 'official_text' => 'Company-only rule',
            'status' => 'verified_text', 'verified_at' => now()]);
        $directory = Storage::disk('local')->path('archive-test');
        $archive = new LegalLibraryArchive(app(PdfPages::class), app(TextNormalizer::class),
            app(AuditLogger::class), $directory);
        expect($archive->export('71'))->toBe(1);
        $manifest = json_decode(file_get_contents($directory.'/manifest.json'), true);
        expect($manifest['schema_version'])->toBe(1)
            ->and(count($manifest['files']))->toBe(1)
            ->and($archive->import())->toBe(1)
            ->and($archive->import())->toBe(1)
            ->and(LegalUnit::where('law_id', $law->id)->count())->toBe(1);
        $file = array_key_first($manifest['files']);
        $payload = json_decode(file_get_contents($directory.'/'.$file), true);
        $payload['law']['number'] = '171';
        $payload['law']['name'] = 'Test law 171';
        $payload['law']['name_en'] = 'Test law 171';
        $freshFile = 'law-171-1987-labor.json';
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n";
        file_put_contents($directory.'/'.$freshFile, $json);
        $manifest['files'] = [$freshFile => hash('sha256', $json)];
        file_put_contents($directory.'/manifest.json', json_encode($manifest));
        expect($archive->import())->toBe(1);
        $fresh = Law::where('number', '171')->firstOrFail();
        $imported = LegalUnit::where('law_id', $fresh->id)->firstOrFail();
        expect($imported->status->value)->toBe('verified_auto')
            ->and($imported->source_page)->toBe(1)
            ->and($imported->importRun->pages()->count())->toBe(1)
            ->and($archive->import())->toBe(1)
            ->and(LegalUnit::where('law_id', $fresh->id)->count())->toBe(1);
        expect($archive->export('171'))->toBe(1)
            ->and($archive->import())->toBe(1);
        file_put_contents($directory.'/'.$freshFile, ' ', FILE_APPEND);
        expect(fn () => $archive->import())->toThrow(RuntimeException::class);
    });
});
