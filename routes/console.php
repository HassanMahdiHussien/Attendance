<?php

use App\Jobs\GenerateDocumentPdf;
use App\Models\Company;
use App\Models\Document;
use App\Services\Cases\CaseAlertDispatcher;
use App\Services\Cases\ExpiredAppealArchiver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

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
