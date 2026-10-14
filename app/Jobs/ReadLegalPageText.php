<?php

namespace App\Jobs;

use App\Jobs\Concerns\UsesLegalImportContext;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\PdfPages;
use App\LegalLibrary\TextLayerProbe;
use App\LegalLibrary\TextLayerRepair;
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
                $repaired = $useLayer && $run->law->text_layer_fixes
                    ? app(TextLayerRepair::class)->repair($layer, $run->law) : null;
                $regulation = $run->law->kind->value === 'internal_regulation' && $run->secondary_source_path === null;
                $ocr = ! $useLayer && $regulation
                    ? $pdf->ocrWithConfidence(Storage::disk('local')->path($page->image_path)) : null;
                $confidence = $ocr['confidence'] ?? null;
                $minimum = max(0, min(100, (float) config('displanery.legal_library.ocr_confidence_min', 85)));
                $confident = $regulation && (($useLayer && $probe->usable($layer))
                    || ($confidence !== null && $confidence >= $minimum));
                $page->update(['text' => $useLayer ? ($repaired['text'] ?? $layer) : ($ocr['text'] ?? $pdf->ocr(Storage::disk('local')->path($page->image_path))),
                    'text_method' => $useLayer ? 'text' : 'ocr', 'text_status' => 'done',
                    'text_repairs' => $repaired['counts'] ?? null,
                    'ocr_confidence' => $confidence,
                    'ai_status' => $confident ? 'skipped' : $page->ai_status,
                    'ai_source' => $confident ? null : $page->ai_source,
                    'attempts_text' => $page->attempts_text + 1]);
                if ($regulation && ! $confident && $run->reading_mode === 'now') {
                    ReadLegalPageWithAi::dispatch($page->id, $run->company_id);
                }
            } catch (Throwable $exception) {
                $page->update(['text_status' => 'failed', 'attempts_text' => $page->attempts_text + 1,
                    'last_error' => mb_substr($exception->getMessage(), 0, 1900)]);
                if ($run->law->kind->value === 'internal_regulation' && $run->secondary_source_path === null
                    && $run->reading_mode === 'now') {
                    ReadLegalPageWithAi::dispatch($page->id, $run->company_id);
                }
            }
            if ($run->reading_mode === 'batch' && $run->law->kind->value === 'internal_regulation'
                && $run->secondary_source_path === null && ! $run->pages()->where('text_status', 'pending')->exists()) {
                SubmitLegalReadingBatch::dispatch($run->id, $run->company_id);
            }
            $progress->evaluate($run);
        });
    }
}
