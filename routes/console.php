<?php

use App\Enums\CaseStatus;
use App\Jobs\BuildLegalUnits;
use App\Jobs\GenerateDocumentPdf;
use App\Jobs\ReadLegalPageText;
use App\Jobs\ReadLegalPageWithAi;
use App\LegalLibrary\ImportProgress;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\LegalImportRun;
use App\Models\ReportExport;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseAlertDispatcher;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\ExpiredAppealArchiver;
use App\Support\HealthPing;
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
    try {
        app(CaseAlertDispatcher::class)->run();
        app(HealthPing::class)->success('deadline_alerts');
    } catch (Throwable $error) {
        app(HealthPing::class)->failure('deadline_alerts', 'Deadline alert dispatch failed');
        throw $error;
    }
})->purpose('Send case deadline alerts');

Artisan::command('cases:archive-expired-appeals', function (): void {
    app(ExpiredAppealArchiver::class)->run();
})->purpose('Archive expired appeal windows');

Schedule::command('cases:send-deadline-alerts')->hourly();
Schedule::command('cases:archive-expired-appeals')->hourly();

Schedule::command('backup:run')->dailyAt('01:00')->timezone('Asia/Baghdad')
    ->when(fn () => config('displanery.backups.enabled'))->withoutOverlapping();
Schedule::command('backup:clean')->dailyAt('02:30')->timezone('Asia/Baghdad')
    ->when(fn () => config('displanery.backups.enabled'))->withoutOverlapping();
Schedule::command('backup:monitor')->hourly()->timezone('Asia/Baghdad')
    ->when(fn () => config('displanery.backups.enabled'))->withoutOverlapping();
Schedule::command('backup:restore-drill')->monthlyOn(1, '03:30')->timezone('Asia/Baghdad')
    ->when(fn () => config('displanery.backups.enabled'))->withoutOverlapping();
Schedule::command('ops:check-failures')->hourly()->timezone('Asia/Baghdad')
    ->when(fn () => filled(config('displanery.healthchecks.queue_failures')))->withoutOverlapping();

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

Artisan::command('report-exports:prune', function (): void {
    app(TenantContext::class)->runAcrossAllCompanies(function (): void {
        ReportExport::where('status', 'ready')->where('expires_at', '<=', now())
            ->chunkById(100, function ($exports): void {
                foreach ($exports as $export) {
                    $work = function () use ($export): void {
                        if ($export->file_path === "report-exports/{$export->id}.{$export->format}") {
                            Storage::disk('local')->delete($export->file_path);
                        }
                        $export->forceFill(['status' => 'expired', 'file_path' => null, 'file_sha256' => null])->save();
                    };
                    $export->company_id === null ? app(TenantContext::class)->runAcrossAllCompanies($work)
                        : app(TenantContext::class)->runForCompany($export->company_id, $work);
                }
            });
    });
})->purpose('Expire seven-day report exports');

Schedule::command('report-exports:prune')->daily();

Artisan::command('ai:reconcile-drafting', function (): void {
    DisciplinaryCase::withoutGlobalScopes()->whereNotNull('ai_request_id')
        ->where('ai_request_started_at', '<=', now()->subMinutes(20))->chunkById(100, function ($cases): void {
            foreach ($cases as $stale) {
                app(TenantContext::class)->runForCompany($stale->company_id, function () use ($stale): void {
                    DB::transaction(function () use ($stale): void {
                        $case = DisciplinaryCase::query()->lockForUpdate()->find($stale->id);
                        if (! $case || ! $case->ai_request_id || $case->ai_request_started_at?->gt(now()->subMinutes(20))) {
                            return;
                        }
                        $from = CaseStatus::tryFrom((string) $case->ai_request_from_status);
                        if ($from && in_array($case->status, [CaseStatus::AiDraftingDefenseLetter,
                            CaseStatus::AiDraftingRecommendations, CaseStatus::AiVerifyAndClose], true)) {
                            app(CaseWorkflow::class)->transition($case, $from, null, 'AI drafting timed out');
                        }
                        $operation = $case->ai_request_document_type;
                        $case->forceFill(['ai_request_id' => null, 'ai_request_document_type' => null,
                            'ai_request_from_status' => null, 'ai_request_attachment_id' => null, 'ai_request_started_at' => null])->save();
                        app(CaseEventLogger::class)->log($case, in_array($operation, ['response_transcription', 'signature_presence'], true)
                            ? 'ai_request_expired' : 'ai_draft_failed', ['operation' => $operation, 'reason' => 'AI unavailable — write it manually or try again']);
                    });
                });
            }
        });
})->purpose('Return stalled AI drafting cases to their previous state');

Schedule::command('ai:reconcile-drafting')->everyTenMinutes();

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
