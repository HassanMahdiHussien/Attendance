<?php

use App\Jobs\BuildLegalUnits;
use App\Jobs\ReadLegalPageText;
use App\Jobs\ReadLegalPageWithAi;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\LawImportService;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;

test('unavailable AI holds the run and terminal readings dispatch one build', function (): void {
    Bus::fake([BuildLegalUnits::class]);
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create();
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'auto', 'state' => 'reading',
            'started_by' => $actor->id, 'started_at' => now()]);
        $page = LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'page.png', 'image_sha256' => str_repeat('b', 64),
            'text_status' => 'done', 'text' => 'المادة 1 حكم', 'ai_status' => 'unavailable']);
        app(ImportProgress::class)->evaluate($run);
        expect($run->fresh()->state)->toBe('ai_pending');
        $run->fresh()->update(['state' => 'reading']);
        $page->update(['ai_status' => 'failed']);
        app(ImportProgress::class)->evaluate($run);
        expect($run->fresh()->state)->toBe('splitting');
        app(ImportProgress::class)->evaluate($run);
        expect($run->fresh()->state)->toBe('splitting');
    });
});

test('AI retry resets only the second reading', function (): void {
    Bus::fake([ReadLegalPageWithAi::class]);
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create();
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'auto', 'state' => 'ai_pending',
            'started_by' => $actor->id, 'started_at' => now()]);
        $page = LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'page.png', 'image_sha256' => str_repeat('b', 64),
            'text_status' => 'done', 'text' => 'المادة 1', 'ai_status' => 'unavailable']);
        app(LawImportService::class)->retryAi($run, $actor);
        expect($run->fresh()->state)->toBe('reading');
        expect($page->fresh()->text)->toBe('المادة 1');
        expect($page->fresh()->text_status)->toBe('done');
        expect($page->fresh()->ai_status)->toBe('pending');
    });
});

test('reconcile queues missing page work for a stale reading run', function (): void {
    Bus::fake([ReadLegalPageText::class, ReadLegalPageWithAi::class]);
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create();
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'auto', 'state' => 'reading',
            'started_by' => $actor->id, 'started_at' => now()]);
        LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => 'page.png', 'image_sha256' => str_repeat('b', 64)]);
        $run->update(['updated_at' => now()->subMinutes(31)]);
        Artisan::call('legal-library:reconcile');
        Bus::assertDispatched(ReadLegalPageText::class);
        Bus::assertDispatched(ReadLegalPageWithAi::class);
    });
});
