<?php

namespace App\Jobs;

use App\Jobs\Concerns\UsesLegalImportContext;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\PdfPages;
use App\LegalLibrary\TextLayerProbe;
use App\Models\LegalImportPage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReadLegalPageText implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesLegalImportContext;

    public int $timeout = 180;

    public function __construct(public int $pageId, public ?int $companyId)
    {
        $this->afterCommit();
    }

    public function handle(PdfPages $pdf, TextLayerProbe $probe, ImportProgress $progress): void
    {
        $this->withinImportContext($this->companyId, function () use ($pdf, $probe, $progress): void {
            $page = LegalImportPage::findOrFail($this->pageId);
            if ($page->text_status !== 'pending' || $page->run->state === 'cancelled') {
                return;
            }
            $run = $page->run;
            try {
                $pdfPath = Storage::disk('local')->path($run->source_path);
                $layer = $run->mode === 'ocr' ? '' : $pdf->text($pdfPath, $page->page_number);
                $useLayer = $run->mode === 'text' || ($run->mode === 'auto' && $probe->usable($layer));
                $page->update(['text' => $useLayer ? $layer : $pdf->ocr(Storage::disk('local')->path($page->image_path)),
                    'text_method' => $useLayer ? 'text' : 'ocr', 'text_status' => 'done',
                    'attempts_text' => $page->attempts_text + 1]);
            } catch (Throwable $exception) {
                $page->update(['text_status' => 'failed', 'attempts_text' => $page->attempts_text + 1,
                    'last_error' => mb_substr($exception->getMessage(), 0, 1900)]);
            }
            $progress->evaluate($run);
        });
    }
}
