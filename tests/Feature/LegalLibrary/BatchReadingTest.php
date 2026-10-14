<?php

use App\Jobs\BuildLegalUnits;
use App\LegalLibrary\BatchClient;
use App\LegalLibrary\BatchPageReader;
use App\Models\AiGenerationLog;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeClaudeClient;

test('one fake batch maps out-of-order results and logs every page without contacting Claude', function (): void {
    Bus::fake([BuildLegalUnits::class]);
    config()->set('services.anthropic.key', 'fake-test-key');
    $fake = new FakeClaudeClient;
    app()->instance(BatchClient::class, $fake);
    app(TenantContext::class)->runAcrossAllCompanies(function () use ($fake): void {
        $actor = User::factory()->create(['primary_role' => 'platform_admin', 'company_id' => null]);
        $law = Law::factory()->create(['kind' => 'statute']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'source.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'auto', 'reading_mode' => 'batch',
            'batch_state' => 'submitting', 'state' => 'reading', 'started_by' => $actor->id, 'started_at' => now()]);
        ob_start();
        imagepng(imagecreatetruecolor(4, 4));
        $png = ob_get_clean();
        $pages = [];
        foreach ([1, 2] as $number) {
            $path = "legal-library/test-page-{$number}.png";
            Storage::disk('local')->put($path, $png);
            $pages[] = LegalImportPage::create(['run_id' => $run->id, 'page_number' => $number,
                'image_path' => $path, 'image_sha256' => hash('sha256', $png),
                'text_status' => 'done', 'text' => 'المادة '.$number, 'ai_status' => 'pending']);
        }
        $reader = app(BatchPageReader::class);
        $reader->submit($run);
        expect($run->fresh()->batch_id)->toBe('msgbatch_fake')
            ->and(array_column($fake->batchRequests, 'customID'))->toBe(['page-'.$pages[0]->id, 'page-'.$pages[1]->id]);
        $fake->batchStatus = 'ended';
        $fake->batchRows = [
            ['custom_id' => 'page-'.$pages[1]->id, 'type' => 'succeeded',
                'output' => json_encode(['text' => 'المادة 2', 'illegible' => false])],
            ['custom_id' => 'page-'.$pages[0]->id, 'type' => 'expired'],
        ];
        $reader->poll($run);
        expect($pages[0]->fresh()->ai_status)->toBe('unavailable')
            ->and($pages[1]->fresh()->ai_status)->toBe('done')
            ->and($pages[1]->fresh()->ai_source)->toBe('api_batch')
            ->and($run->fresh()->state)->toBe('ai_pending')
            ->and(AiGenerationLog::where('touchpoint', 'legal_library_reading')->count())->toBe(4);
        $reader->poll($run);
        expect(AiGenerationLog::where('touchpoint', 'legal_library_reading')->count())->toBe(4);
    });
});
