<?php

namespace App\Jobs;

use App\Jobs\Concerns\UsesLegalImportContext;
use App\LegalLibrary\AiUnavailable;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\PageReader;
use App\LegalLibrary\PermanentPageFailure;
use App\LegalLibrary\RetryablePageFailure;
use App\Models\LegalImportPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReadLegalPageWithAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesLegalImportContext;

    public int $timeout = 300;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public int $pageId, public ?int $companyId)
    {
        $this->afterCommit();
    }

    public function handle(PageReader $reader, ImportProgress $progress): void
    {
        $this->withinImportContext($this->companyId, function () use ($reader, $progress): void {
            $page = LegalImportPage::findOrFail($this->pageId);
            if ($page->ai_status !== 'pending' || $page->run->state === 'cancelled'
                || $page->run->reading_mode === 'batch' || $page->run->secondary_source_path !== null
                || ($page->run->law->kind->value === 'internal_regulation' && $page->text_status === 'pending')) {
                return;
            }
            $run = $page->run;
            $page->update(['ai_source' => 'api']);
            try {
                $reading = $reader->read(Storage::disk('local')->path($page->image_path),
                    ['company_id' => $run->company_id, 'actor_user_id' => $run->started_by,
                        'law_id' => $run->law_id, 'run_id' => $run->id, 'page' => $page->page_number]);
                $page->update(['ai_text' => $reading->text, 'ai_illegible' => $reading->illegible,
                    'ai_status' => 'done', 'ai_source' => 'api', 'attempts_ai' => $page->attempts_ai + 1]);
            } catch (AiUnavailable $exception) {
                $page->update(['ai_status' => 'unavailable', 'attempts_ai' => $page->attempts_ai + 1,
                    'last_error' => $exception->getMessage()]);
            } catch (PermanentPageFailure $exception) {
                $page->update(['ai_status' => 'failed', 'attempts_ai' => $page->attempts_ai + 1,
                    'last_error' => mb_substr($exception->getMessage(), 0, 1900)]);
            } catch (RetryablePageFailure $exception) {
                $page->increment('attempts_ai');
                if ($this->attempts() >= $this->tries) {
                    $page->update(['ai_status' => 'failed', 'last_error' => mb_substr($exception->getMessage(), 0, 1900)]);
                } else {
                    throw $exception;
                }
            } finally {
                $progress->evaluate($run);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->withinImportContext($this->companyId, function () use ($exception): void {
            $page = LegalImportPage::find($this->pageId);
            if ($page === null || $page->ai_status !== 'pending') {
                return;
            }
            $page->update(['ai_status' => 'failed',
                'last_error' => mb_substr($exception?->getMessage() ?? 'AI reading failed.', 0, 1900)]);
            app(ImportProgress::class)->evaluate($page->run);
        });
    }
}
