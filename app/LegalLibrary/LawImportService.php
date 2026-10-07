<?php

namespace App\LegalLibrary;

use App\Enums\ImportMode;
use App\Jobs\ReadLegalPageWithAi;
use App\Jobs\RenderLegalPages;
use App\Models\Law;
use App\Models\LegalImportRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class LawImportService
{
    public function __construct(private PdfPages $pdf, private AuditLogger $audit) {}

    public function start(Law $law, User $actor, UploadedFile|string|null $source, ?int $pageFrom, ?int $pageTo, ImportMode $mode): LegalImportRun
    {
        abort_unless($actor->can('import', $law), 403);
        if ($source === null) {
            $source = is_file(base_path($law->source_file_path))
                ? base_path($law->source_file_path) : Storage::disk('local')->path($law->source_file_path);
        }
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        if (! is_file($path) || filesize($path) > (int) config('displanery.legal_library.max_file_mb', 50) * 1024 * 1024) {
            throw ValidationException::withMessages(['source' => 'Source file is missing or exceeds the size limit.']);
        }
        $extension = strtolower($source instanceof UploadedFile ? $source->getClientOriginalExtension() : pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'txt', 'docx'], true) || ($law->kind->value === 'statute' && $extension !== 'pdf')) {
            throw ValidationException::withMessages(['source' => 'Unsupported source format.']);
        }
        $sha = hash_file('sha256', $path);
        $relative = "legal-library/sources/{$sha}.{$extension}";
        if (! Storage::disk('local')->exists($relative)) {
            Storage::disk('local')->put($relative, file_get_contents($path));
        } elseif (hash_file('sha256', Storage::disk('local')->path($relative)) !== $sha) {
            throw ValidationException::withMessages(['source' => 'Stored source hash differs.']);
        }
        try {
            $count = $extension === 'pdf' ? $this->pdf->count(Storage::disk('local')->path($relative)) : null;
        } catch (Throwable $exception) {
            return DB::transaction(function () use ($law, $actor, $relative, $sha, $pageFrom, $pageTo, $mode, $exception): LegalImportRun {
                Law::withoutGlobalScopes()->whereKey($law->id)->lockForUpdate()->firstOrFail();
                if (LegalImportRun::withoutGlobalScopes()->where('law_id', $law->id)
                    ->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                    throw ValidationException::withMessages(['law' => 'This law already has an active import.']);
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
        if ($count !== null && ! ($law->kind->value === 'statute' && $law->number === '18')) {
            $pageFrom ??= 1;
            $pageTo ??= $count;
        }
        if ($count !== null && ($pageFrom === null || $pageTo === null || $pageFrom < 1 || $pageTo < $pageFrom || $pageTo > $count || $pageTo - $pageFrom + 1 > (int) config('displanery.legal_library.max_pages', 300))) {
            throw ValidationException::withMessages(['page_from' => 'Choose a valid page range within the import limit.']);
        }

        return DB::transaction(function () use ($law, $actor, $relative, $sha, $pageFrom, $pageTo, $mode, $extension): LegalImportRun {
            Law::withoutGlobalScopes()->whereKey($law->id)->lockForUpdate()->firstOrFail();
            if (LegalImportRun::withoutGlobalScopes()->where('law_id', $law->id)
                ->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                throw ValidationException::withMessages(['law' => 'This law already has an active import.']);
            }
            $run = LegalImportRun::create(['law_id' => $law->id, 'company_id' => $law->company_id,
                'source_path' => $relative, 'source_sha256' => $sha, 'page_from' => $pageFrom,
                'page_to' => $pageTo, 'mode' => $mode->value, 'state' => 'queued',
                'started_by' => $actor->id, 'started_at' => now()]);
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
                throw ValidationException::withMessages(['run' => 'This run is still active.']);
            }
            if (LegalImportRun::withoutGlobalScopes()->where('law_id', $locked->law_id)
                ->where('id', '!=', $locked->id)->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                throw ValidationException::withMessages(['run' => 'Another import is active for this law.']);
            }
            $pages = $locked->pages()->whereIn('ai_status', ['failed', 'unavailable'])->get();
            if ($pages->isEmpty()) {
                throw ValidationException::withMessages(['run' => 'There are no AI pages to retry.']);
            }
            $locked->update(['state' => 'reading', 'finished_at' => null]);
            foreach ($pages as $page) {
                $page->update(['ai_status' => 'pending', 'last_error' => null]);
                ReadLegalPageWithAi::dispatch($page->id, $locked->company_id)->afterCommit();
            }
            $this->audit->log('legal_import.ai_retry', $locked, ['pages' => $pages->pluck('page_number')->all()], $actor);
        });
    }
}
