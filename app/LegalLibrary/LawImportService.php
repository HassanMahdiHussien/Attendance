<?php

namespace App\LegalLibrary;

use App\Enums\ImportMode;
use App\Jobs\CancelLegalReadingBatch;
use App\Jobs\ReadLegalPageWithAi;
use App\Jobs\RenderLegalPages;
use App\Jobs\SubmitLegalReadingBatch;
use App\Models\Law;
use App\Models\LegalImportRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LawImportService
{
    public function __construct(private PdfPages $pdf, private AuditLogger $audit) {}

    public function start(Law $law, User $actor, UploadedFile|string|null $source, ?int $pageFrom, ?int $pageTo,
        ImportMode $mode, ?string $readingMode = null, ?UploadedFile $transcription = null,
        ?UploadedFile $secondary = null, ?string $transcriptionNote = null, ?string $secondaryNote = null): LegalImportRun
    {
        abort_unless($actor->can('import', $law), 403);
        $readingMode ??= $law->kind->value === 'statute' ? 'batch' : 'now';
        if (! in_array($readingMode, ['now', 'batch'], true)) {
            throw ValidationException::withMessages(['reading_mode' => __('messages.legal.source_type_unsupported')]);
        }
        if ($law->requires_page_range && ($pageFrom === null || $pageTo === null)) {
            throw ValidationException::withMessages(['page_from' => __('messages.legal.page_range_required')]);
        }
        if ($source === null) {
            $source = is_file(base_path($law->source_file_path))
                ? base_path($law->source_file_path) : Storage::disk('local')->path($law->source_file_path);
        }
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        if (! is_file($path) || filesize($path) > (int) config('displanery.legal_library.max_file_mb', 50) * 1024 * 1024) {
            throw ValidationException::withMessages(['source' => __('messages.legal.source_missing_or_large')]);
        }
        $extension = strtolower($source instanceof UploadedFile ? $source->getClientOriginalExtension() : pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'txt', 'docx'], true) || ($law->kind->value === 'statute' && $extension !== 'pdf')) {
            throw ValidationException::withMessages(['source' => __('messages.legal.source_type_unsupported')]);
        }
        if ($extension !== 'pdf' && ($readingMode === 'batch' || $transcription !== null || $secondary !== null)) {
            throw ValidationException::withMessages(['source' => __('messages.legal.source_type_unsupported')]);
        }
        $sha = hash_file('sha256', $path);
        $relative = "legal-library/sources/{$sha}.{$extension}";
        if (! Storage::disk('local')->exists($relative)) {
            Storage::disk('local')->put($relative, file_get_contents($path));
        } elseif (hash_file('sha256', Storage::disk('local')->path($relative)) !== $sha) {
            throw ValidationException::withMessages(['source' => __('messages.legal.source_hash_mismatch')]);
        }
        try {
            $count = $extension === 'pdf' ? $this->pdf->count(Storage::disk('local')->path($relative)) : null;
        } catch (Throwable $exception) {
            return DB::transaction(function () use ($law, $actor, $relative, $sha, $pageFrom, $pageTo, $mode, $exception): LegalImportRun {
                Law::withoutGlobalScopes()->whereKey($law->id)->lockForUpdate()->firstOrFail();
                if (LegalImportRun::withoutGlobalScopes()->where('law_id', $law->id)
                    ->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                    throw ValidationException::withMessages(['law' => __('messages.legal.law_import_active')]);
                }
                $run = LegalImportRun::create(['law_id' => $law->id, 'company_id' => $law->company_id,
                    'source_path' => $relative, 'source_sha256' => $sha, 'page_from' => $pageFrom,
                    'page_to' => $pageTo, 'mode' => $mode->value, 'state' => 'failed',
                    'started_by' => $actor->id, 'started_at' => now(), 'finished_at' => now(),
                    'error' => mb_substr($exception->getMessage(), 0, 1900)]);
                $this->audit->log('legal_import.failed', $run, ['error' => $run->error], $actor);

                return $run;
            });
        }
        if ($count !== null && ! $law->requires_page_range) {
            $pageFrom ??= 1;
            $pageTo ??= $count;
        }
        if ($count !== null && ($pageFrom === null || $pageTo === null || $pageFrom < 1 || $pageTo < $pageFrom || $pageTo > $count || $pageTo - $pageFrom + 1 > (int) config('displanery.legal_library.max_pages', 300))) {
            throw ValidationException::withMessages(['page_from' => __('messages.legal.page_range_limit')]);
        }

        return DB::transaction(function () use ($law, $actor, $relative, $sha, $pageFrom, $pageTo, $mode,
            $extension, $readingMode, $transcription, $secondary, $transcriptionNote, $secondaryNote): LegalImportRun {
            Law::withoutGlobalScopes()->whereKey($law->id)->lockForUpdate()->firstOrFail();
            if (LegalImportRun::withoutGlobalScopes()->where('law_id', $law->id)
                ->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                throw ValidationException::withMessages(['law' => __('messages.legal.law_import_active')]);
            }
            $run = LegalImportRun::create(['law_id' => $law->id, 'company_id' => $law->company_id,
                'source_path' => $relative, 'source_sha256' => $sha, 'page_from' => $pageFrom,
                'page_to' => $pageTo, 'mode' => $mode->value, 'reading_mode' => $readingMode,
                'reading_model' => config('displanery.ai.legal_reading_model'),
                'reading_effort' => config('displanery.ai.legal_reading_effort'), 'state' => 'queued',
                'started_by' => $actor->id, 'started_at' => now()]);
            app(ExternalReadings::class)->attach($run, $actor, $transcription, $secondary,
                $transcriptionNote, $secondaryNote);
            $law->update(['source_file_path' => $relative, 'source_sha256' => $sha, 'import_mode' => $mode->value]);
            $this->audit->log('legal_import.started', $run, ['source_sha256' => $sha], $actor);
            RenderLegalPages::dispatch($run->id, $extension)->afterCommit();

            return $run;
        });
    }

    public function retryAi(LegalImportRun $run, User $actor): void
    {
        abort_unless($actor->can('import', $run->law), 403);
        DB::transaction(function () use ($run, $actor): void {
            $locked = LegalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($locked->state, ['ai_pending', 'completed'], true)) {
                throw ValidationException::withMessages(['run' => __('messages.legal.import_still_active')]);
            }
            if (LegalImportRun::withoutGlobalScopes()->where('law_id', $locked->law_id)
                ->where('id', '!=', $locked->id)->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                throw ValidationException::withMessages(['run' => __('messages.legal.another_import_active')]);
            }
            $pages = $locked->pages()->whereIn('ai_status', ['failed', 'unavailable'])->get();
            if ($pages->isEmpty()) {
                throw ValidationException::withMessages(['run' => __('messages.legal.no_ai_pages_to_reread')]);
            }
            $locked->update(['state' => 'reading', 'finished_at' => null,
                'batch_id' => null, 'batch_state' => $locked->reading_mode === 'batch' ? 'submitting' : null,
                'reading_model' => config('displanery.ai.legal_reading_model'),
                'reading_effort' => config('displanery.ai.legal_reading_effort')]);
            foreach ($pages as $page) {
                $page->update(['ai_status' => 'pending', 'last_error' => null]);
                if ($locked->reading_mode === 'now') {
                    ReadLegalPageWithAi::dispatch($page->id, $locked->company_id)->afterCommit();
                }
            }
            if ($locked->reading_mode === 'batch') {
                SubmitLegalReadingBatch::dispatch($locked->id, $locked->company_id)->afterCommit();
            }
            $this->audit->log('legal_import.ai_retry', $locked, ['pages' => $pages->pluck('page_number')->all()], $actor);
        });
    }

    public function cancel(LegalImportRun $run, User $actor, string $reason): void
    {
        abort_unless($actor->can('import', $run->law), 403);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('messages.legal.cancellation_reason_required')]);
        }
        DB::transaction(function () use ($run, $actor, $reason): void {
            $locked = LegalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->state, ['completed', 'failed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['run' => __('messages.legal.import_already_finished')]);
            }
            $locked->update(['state' => 'cancelled', 'finished_at' => now()]);
            $this->audit->log('legal_import.cancelled', $locked, ['reason' => $reason], $actor);
            if ($locked->batch_id !== null) {
                CancelLegalReadingBatch::dispatch($locked->id, $locked->company_id)->afterCommit();
            }
        });
    }

    public function previewPages(Law $law, UploadedFile|string|null $source, int $from, int $to): array
    {
        abort_unless(auth()->user()?->can('import', $law), 403);
        if ($source === null) {
            $source = is_file(base_path($law->source_file_path))
                ? base_path($law->source_file_path) : Storage::disk('local')->path($law->source_file_path);
        }
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        $extension = $source instanceof UploadedFile ? $source->getClientOriginalExtension() : pathinfo($path, PATHINFO_EXTENSION);
        if (! is_file($path) || strtolower($extension) !== 'pdf'
            || filesize($path) > (int) config('displanery.legal_library.max_file_mb', 50) * 1024 * 1024) {
            throw ValidationException::withMessages(['source' => __('messages.legal.pdf_size_invalid')]);
        }
        $count = $this->pdf->count($path);
        if ($from < 1 || $to < $from || $to > $count || $to - $from + 1 > (int) config('displanery.legal_library.max_pages', 300)) {
            throw ValidationException::withMessages(['page_from' => __('messages.legal.page_range_invalid')]);
        }
        $directory = 'legal-library/previews/'.Str::uuid();
        Storage::disk('local')->makeDirectory($directory);
        $result = [];
        foreach (array_unique([$from, $to]) as $page) {
            $prefix = Storage::disk('local')->path($directory.'/page-'.$page);
            $png = $this->pdf->render($path, $page, $prefix);
            $sourceImage = imagecreatefrompng($png);
            $width = imagesx($sourceImage);
            $height = imagesy($sourceImage);
            $scale = min(1, 900 / max($width, $height));
            $preview = imagescale($sourceImage, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            $jpeg = $prefix.'.jpg';
            imagejpeg($preview, $jpeg, 80);
            imagedestroy($sourceImage);
            imagedestroy($preview);
            unlink($png);
            $result[$page] = $directory.'/page-'.$page.'.jpg';
        }

        return $result;
    }
}
