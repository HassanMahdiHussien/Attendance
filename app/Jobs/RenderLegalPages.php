<?php

namespace App\Jobs;

use App\Jobs\Concerns\UsesLegalImportContext;
use App\LegalLibrary\ArticleSplitter;
use App\LegalLibrary\DocxText;
use App\LegalLibrary\ExternalReadings;
use App\LegalLibrary\ImportProgress;
use App\LegalLibrary\PdfPages;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RenderLegalPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesLegalImportContext;

    // Renders every page of the source (up to the page limit); config queue retry_after must stay above it.
    public int $timeout = 900;

    public function __construct(public int $runId, public string $extension)
    {
        $this->afterCommit();
    }

    public function handle(PdfPages $pdf, DocxText $docx, ArticleSplitter $splitter, AuditLogger $audit): void
    {
        $run = LegalImportRun::withoutGlobalScopes()->findOrFail($this->runId);
        $this->withinImportContext($run->company_id, function () use ($run, $pdf, $docx, $splitter, $audit): void {
            if ($run->state !== 'queued') {
                return;
            }
            $source = Storage::disk('local')->path($run->source_path);
            try {
                if ($this->extension !== 'pdf') {
                    $text = $this->extension === 'docx' ? $docx->read($source) : file_get_contents($source);
                    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
                    if (! mb_check_encoding($text, 'UTF-8')) {
                        throw new \RuntimeException('Text source must be UTF-8.');
                    }
                    $units = $splitter->split([0 => $text]);
                    DB::transaction(function () use ($run, $units, $audit): void {
                        foreach ($units as $unit) {
                            $status = isset($unit['tags']['mismatch_reason']) ? 'mismatch' : 'verified_text';
                            $created = LegalUnit::create(['law_id' => $run->law_id, 'company_id' => $run->company_id,
                                'import_run_id' => $run->id, 'article_number' => $unit['article_number'],
                                'clause_path' => $unit['clause_path'], 'official_text' => $unit['text'],
                                'source_page' => null, 'version' => 1, 'status' => $status,
                                'tags' => $unit['tags'], 'verified_at' => $status === 'verified_text' ? now() : null]);
                            if ($status === 'verified_text') {
                                $audit->log('legal_unit.verified', $created,
                                    ['method' => 'text_source', 'import_run_id' => $run->id]);
                            }
                        }
                        $run->update(['state' => 'completed', 'finished_at' => now(), 'counts' => [
                            'pages' => 0, 'units' => count($units), 'verified_text' => collect($units)
                                ->reject(fn ($unit) => isset($unit['tags']['mismatch_reason']))->count(),
                            'mismatch' => collect($units)->filter(fn ($unit) => isset($unit['tags']['mismatch_reason']))->count(),
                            'failed_pages' => 0,
                        ]]);
                        $audit->log('legal_import.completed', $run);
                    });

                    return;
                }
                for ($page = $run->page_from; $page <= $run->page_to; $page++) {
                    if ($run->fresh()->state === 'cancelled') {
                        return;
                    }
                    $relative = "legal-library/runs/{$run->id}/pages/{$page}.png";
                    $prefix = substr(Storage::disk('local')->path($relative), 0, -4);
                    if (! is_dir(dirname($prefix))) {
                        mkdir(dirname($prefix), 0700, true);
                    }
                    $image = $prefix.'.png';
                    $existing = $run->pages()->where('page_number', $page)->first();
                    // Reuse an image only for a page this run already recorded, and only if it is the same
                    // file; a leftover file (e.g. after a database restore reused the run id) is re-rendered.
                    if ($existing !== null && is_file($image)) {
                        if (! hash_equals($existing->image_sha256, hash_file('sha256', $image))) {
                            throw new \RuntimeException("Stored image of page {$page} no longer matches its record.");
                        }
                        $rows[] = $existing;

                        continue;
                    }
                    $pdf->render($source, $page, $prefix);
                    $row = $existing ?? $run->pages()->create(['page_number' => $page, 'image_path' => $relative,
                        'image_sha256' => hash_file('sha256', $image), 'company_id' => $run->company_id]);
                    if ($existing !== null && ! hash_equals($existing->image_sha256, hash_file('sha256', $image))) {
                        throw new \RuntimeException("Re-rendered page {$page} differs from its recorded image.");
                    }
                    $rows[] = $row;
                }
                if ($run->fresh()->state === 'cancelled') {
                    return;
                }
                $transcriptions = app(ExternalReadings::class)->pageTexts($run);
                $run->update(['state' => 'reading']);
                foreach ($rows as $row) {
                    if (isset($transcriptions[$row->page_number])) {
                        $row->update(['text' => $transcriptions[$row->page_number], 'text_method' => 'transcription',
                            'text_status' => 'done']);
                    } else {
                        ReadLegalPageText::dispatch($row->id, $run->company_id);
                    }
                    if ($run->secondary_source_path !== null) {
                        $row->update(['ai_status' => 'done', 'ai_source' => 'secondary_text']);
                    } elseif ($run->law->kind->value !== 'internal_regulation' && $run->reading_mode === 'now') {
                        ReadLegalPageWithAi::dispatch($row->id, $run->company_id);
                    } elseif ($run->law->kind->value === 'internal_regulation'
                        && isset($transcriptions[$row->page_number]) && $run->reading_mode === 'now') {
                        ReadLegalPageWithAi::dispatch($row->id, $run->company_id);
                    }
                }
                if ($run->reading_mode === 'batch' && $run->secondary_source_path === null) {
                    $run->update(['batch_state' => 'submitting']);
                    if ($run->law->kind->value !== 'internal_regulation'
                        || ! $run->pages()->where('text_status', 'pending')->exists()) {
                        SubmitLegalReadingBatch::dispatch($run->id, $run->company_id);
                    }
                }
                if ($run->secondary_source_path !== null) {
                    app(ImportProgress::class)->evaluate($run);
                }
            } catch (Throwable $exception) {
                if ($run->fresh()->state === 'cancelled') {
                    return;
                }
                $run->update(['state' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 1900), 'finished_at' => now()]);
                $audit->log('legal_import.failed', $run, ['error' => $run->error]);
            }
        });
    }
}
