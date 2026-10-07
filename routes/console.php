<?php

use App\Jobs\BuildLegalUnits;
use App\Jobs\GenerateDocumentPdf;
use App\Jobs\ReadLegalPageText;
use App\Jobs\ReadLegalPageWithAi;
use App\LegalLibrary\ImportProgress;
use App\Models\Company;
use App\Models\Document;
use App\Models\LegalImportRun;
use App\Services\Cases\CaseAlertDispatcher;
use App\Services\Cases\ExpiredAppealArchiver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:heartbeat --dispatch')->everyMinute();

Artisan::command('cases:send-deadline-alerts', function (): void {
    app(CaseAlertDispatcher::class)->run();
})->purpose('Send case deadline alerts');

Artisan::command('cases:archive-expired-appeals', function (): void {
    app(ExpiredAppealArchiver::class)->run();
})->purpose('Archive expired appeal windows');

Schedule::command('cases:send-deadline-alerts')->hourly();
Schedule::command('cases:archive-expired-appeals')->hourly();

Artisan::command('legal-library:reconcile', function (): void {
    foreach (Storage::disk('local')->directories('legal-library/previews') as $directory) {
        if (Storage::disk('local')->lastModified($directory) < now()->subDay()->timestamp) {
            Storage::disk('local')->deleteDirectory($directory);
        }
    }
    LegalImportRun::withoutGlobalScopes()->whereIn('state', ['reading', 'splitting'])
        ->where('updated_at', '<=', now()->subMinutes(30))->chunkById(100, function ($runs): void {
            foreach ($runs as $run) {
                $context = app(TenantContext::class);
                $callback = function () use ($run): void {
                    if ($run->state === 'splitting') {
                        BuildLegalUnits::dispatch($run->id);

                        return;
                    }
                    foreach ($run->pages as $page) {
                        if ($page->text_status === 'pending') {
                            ReadLegalPageText::dispatch($page->id, $run->company_id);
                        }
                        if ($page->ai_status === 'pending') {
                            ReadLegalPageWithAi::dispatch($page->id, $run->company_id);
                        }
                    }
                    app(ImportProgress::class)->evaluate($run);
                };
                $run->company_id === null ? $context->runAcrossAllCompanies($callback)
                    : $context->runForCompany($run->company_id, $callback);
            }
        });
})->purpose('Recover stalled legal library imports');

Schedule::command('legal-library:reconcile')->everyTenMinutes();

Artisan::command('documents:retry-pdf {id}', function (int $id): void {
    $row = DB::table('documents')->where('id', $id)->first();
    if (! $row || $row->pdf_status !== 'failed') {
        $this->error('Document does not have a failed PDF.');

        return;
    }
    app(TenantContext::class)->runForCompany(Company::findOrFail($row->company_id), function () use ($id): void {
        $document = Document::findOrFail($id);
        $document->forceFill(['pdf_status' => 'pending', 'pdf_error' => null])->save();
        GenerateDocumentPdf::dispatch($document->id, $document->company_id);
    });
    $this->info('PDF retry queued.');
})->purpose('Retry a failed document PDF');
