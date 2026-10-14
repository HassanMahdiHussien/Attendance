<?php

use App\Exceptions\TenantMismatch;
use App\Jobs\ReadLegalPageWithAi;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\PageReader;
use App\LegalLibrary\PageReading;
use App\Models\Company;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\LegalUnitApproval;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

test('regulation runs, pages, units and approvals are invisible across companies', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'company_admin']);
    $context = app(TenantContext::class);
    [$run, $page, $unit] = $context->runForCompany($a, function () use ($a, $actor): array {
        $law = Law::factory()->create(['company_id' => $a->id, 'kind' => 'internal_regulation']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'company_id' => $a->id,
            'source_path' => 'fixture.pdf', 'source_sha256' => str_repeat('a', 64), 'mode' => 'auto',
            'state' => 'reading', 'started_by' => $actor->id, 'started_at' => now()]);
        $page = LegalImportPage::create(['run_id' => $run->id, 'company_id' => $a->id,
            'page_number' => 1, 'image_path' => 'fixture.png', 'image_sha256' => str_repeat('b', 64)]);
        $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'import_run_id' => $run->id]);

        return [$run, $page, $unit];
    });
    DB::table('legal_unit_approvals')->insert(['company_id' => $a->id,
        'legal_unit_id' => $unit->id, 'approved_by' => $actor->id, 'created_at' => now()]);
    $context->runForCompany($b, function () use ($run, $page, $unit): void {
        expect(LegalImportRun::find($run->id))->toBeNull();
        expect(LegalImportPage::find($page->id))->toBeNull();
        expect(LegalUnit::find($unit->id))->toBeNull();
        expect(LegalUnitApproval::count())->toBe(0);
        expect(fn () => LegalImportPage::create(['run_id' => $run->id, 'company_id' => $page->company_id,
            'page_number' => 2, 'image_path' => 'other.png', 'image_sha256' => str_repeat('c', 64)]))
            ->toThrow(TenantMismatch::class);
    });
    $context->runAcrossAllCompanies(function () use ($run, $page, $unit): void {
        expect(LegalImportRun::find($run->id))->not->toBeNull();
        expect(LegalImportPage::find($page->id))->not->toBeNull();
        expect(LegalUnit::find($unit->id))->not->toBeNull();
        expect(LegalUnitApproval::where('legal_unit_id', $unit->id)->exists())->toBeTrue();
    });
});

test('page jobs set the source company and restore the caller context', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'company_admin']);
    $page = app(TenantContext::class)->runForCompany($a, function () use ($a, $actor): LegalImportPage {
        $law = Law::factory()->create(['company_id' => $a->id, 'kind' => 'internal_regulation']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'auto', 'state' => 'reading',
            'started_by' => $actor->id, 'started_at' => now()]);
        Storage::disk('local')->put('legal-library/test-page.png', 'not examined by fake');

        return LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'legal-library/test-page.png', 'image_sha256' => str_repeat('b', 64),
            'text_status' => 'done', 'text' => 'المادة 1']);
    });
    $reader = new class implements PageReader
    {
        public ?int $seenCompany = null;

        public function read(string $pngPath, array $context): PageReading
        {
            $this->seenCompany = app(TenantContext::class)->companyId();

            return new PageReading('المادة 1', false);
        }
    };
    app(TenantContext::class)->setCompany($b);
    (new ReadLegalPageWithAi($page->id, $a->id))->handle($reader, app(ImportProgress::class));
    expect($reader->seenCompany)->toBe($a->id);
    expect(app(TenantContext::class)->companyId())->toBe($b->id);
});

test('page and approval composite keys reject cross-company raw inserts', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'company_admin']);
    [$run, $unit] = app(TenantContext::class)->runForCompany($a, function () use ($a, $actor): array {
        $law = Law::factory()->create(['company_id' => $a->id, 'kind' => 'internal_regulation']);

        return [LegalImportRun::create(['law_id' => $law->id, 'company_id' => $a->id,
            'source_path' => 'fixture.pdf', 'source_sha256' => str_repeat('a', 64), 'mode' => 'auto',
            'started_by' => $actor->id, 'started_at' => now()]),
            LegalUnit::factory()->create(['law_id' => $law->id])];
    });
    expect(fn () => DB::table('legal_import_pages')->insert(['run_id' => $run->id, 'company_id' => $b->id,
        'page_number' => 1, 'image_path' => 'x', 'image_sha256' => str_repeat('x', 64)]))->toThrow(QueryException::class);
    expect(fn () => DB::table('legal_unit_approvals')->insert(['legal_unit_id' => $unit->id,
        'company_id' => $b->id, 'approved_by' => $actor->id, 'created_at' => now()]))->toThrow(QueryException::class);
});

test('verified text and approvals are immutable even through raw SQL', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    app(TenantContext::class)->runForCompany($company, function () use ($company, $actor): void {
        $law = Law::factory()->create(['company_id' => $company->id, 'kind' => 'internal_regulation']);
        $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'status' => 'verified_text']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'company_id' => $company->id,
            'source_path' => 'fixture.docx', 'source_sha256' => str_repeat('a', 64),
            'mode' => 'auto', 'started_by' => $actor->id, 'started_at' => now()]);
        expect(fn () => DB::table('legal_units')->where('id', $unit->id)->update(['official_text' => 'changed']))->toThrow(QueryException::class);
        expect(fn () => DB::table('legal_units')->where('id', $unit->id)->update(['import_run_id' => $run->id]))->toThrow(QueryException::class);
        expect(fn () => DB::table('legal_units')->where('id', $unit->id)->delete())->toThrow(QueryException::class);
        DB::table('legal_unit_approvals')->insert(['company_id' => $company->id,
            'legal_unit_id' => $unit->id, 'approved_by' => $actor->id, 'created_at' => now()]);
        expect(fn () => DB::table('legal_unit_approvals')->where('legal_unit_id', $unit->id)
            ->update(['approved_by' => $actor->id]))->toThrow(QueryException::class);
        expect(fn () => DB::table('legal_unit_approvals')->where('legal_unit_id', $unit->id)->delete())->toThrow(QueryException::class);
    });
});

test('approval creation requires a reviewer from the regulation company', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $reviewer = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'hr_staff']);
    $outsider = User::factory()->create(['company_id' => $b->id, 'primary_role' => 'hr_staff']);
    Permission::findOrCreate('review_internal_regulation', 'web');
    $outsider->givePermissionTo('review_internal_regulation');
    app(TenantContext::class)->runForCompany($a, function () use ($a, $reviewer, $outsider): void {
        $law = Law::factory()->create(['company_id' => $a->id, 'kind' => 'internal_regulation']);
        $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'status' => 'verified_text']);
        expect(fn () => LegalUnitApproval::create(['company_id' => $a->id,
            'legal_unit_id' => $unit->id, 'approved_by' => $reviewer->id]))->toThrow(TenantMismatch::class);
        expect(fn () => LegalUnitApproval::create(['company_id' => $a->id,
            'legal_unit_id' => $unit->id, 'approved_by' => $outsider->id]))->toThrow(TenantMismatch::class);
        $reviewer->givePermissionTo('review_internal_regulation');
        expect(LegalUnitApproval::create(['company_id' => $a->id,
            'legal_unit_id' => $unit->id, 'approved_by' => $reviewer->id])->exists)->toBeTrue();
    });
});
