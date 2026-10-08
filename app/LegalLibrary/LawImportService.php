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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class LawImportService
{
    public function __construct(private PdfPages $pdf, private AuditLogger $audit) {}

    public function start(Law $law, User $actor, UploadedFile|string|null $source, ?int $pageFrom, ?int $pageTo, ImportMode $mode): LegalImportRun
    {
        abort_unless($actor->can('import', $law), 403);
        if ($law->requires_page_range && ($pageFrom === null || $pageTo === null)) {
            throw ValidationException::withMessages(['page_from' => 'يلزم تحديد نطاق الصفحات لهذا القانون.']);
        }
        if ($source === null) {
            $source = is_file(base_path($law->source_file_path))
                ? base_path($law->source_file_path) : Storage::disk('local')->path($law->source_file_path);
        }
        $path = $source instanceof UploadedFile ? $source->getRealPath() : $source;
        if (! is_file($path) || filesize($path) > (int) config('displanery.legal_library.max_file_mb', 50) * 1024 * 1024) {
            throw ValidationException::withMessages(['source' => 'ملف المصدر غير موجود أو يتجاوز الحد المسموح للحجم.']);
        }
        $extension = strtolower($source instanceof UploadedFile ? $source->getClientOriginalExtension() : pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['pdf', 'txt', 'docx'], true) || ($law->kind->value === 'statute' && $extension !== 'pdf')) {
            throw ValidationException::withMessages(['source' => 'صيغة ملف المصدر غير مدعومة.']);
        }
        $sha = hash_file('sha256', $path);
        $relative = "legal-library/sources/{$sha}.{$extension}";
        if (! Storage::disk('local')->exists($relative)) {
            Storage::disk('local')->put($relative, file_get_contents($path));
        } elseif (hash_file('sha256', Storage::disk('local')->path($relative)) !== $sha) {
            throw ValidationException::withMessages(['source' => 'بصمة ملف المصدر المخزن غير مطابقة.']);
        }
        try {
            $count = $extension === 'pdf' ? $this->pdf->count(Storage::disk('local')->path($relative)) : null;
        } catch (Throwable $exception) {
            return DB::transaction(function () use ($law, $actor, $relative, $sha, $pageFrom, $pageTo, $mode, $exception): LegalImportRun {
                Law::withoutGlobalScopes()->whereKey($law->id)->lockForUpdate()->firstOrFail();
                if (LegalImportRun::withoutGlobalScopes()->where('law_id', $law->id)
                    ->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                    throw ValidationException::withMessages(['law' => 'توجد عملية استيراد نشطة لهذا القانون.']);
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
            throw ValidationException::withMessages(['page_from' => 'اختر نطاق صفحات صحيحاً ضمن حد الاستيراد.']);
        }

        return DB::transaction(function () use ($law, $actor, $relative, $sha, $pageFrom, $pageTo, $mode, $extension): LegalImportRun {
            Law::withoutGlobalScopes()->whereKey($law->id)->lockForUpdate()->firstOrFail();
            if (LegalImportRun::withoutGlobalScopes()->where('law_id', $law->id)
                ->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                throw ValidationException::withMessages(['law' => 'توجد عملية استيراد نشطة لهذا القانون.']);
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
                throw ValidationException::withMessages(['run' => 'عملية الاستيراد هذه ما زالت نشطة.']);
            }
            if (LegalImportRun::withoutGlobalScopes()->where('law_id', $locked->law_id)
                ->where('id', '!=', $locked->id)->whereIn('state', ['queued', 'reading', 'ai_pending', 'splitting'])->exists()) {
                throw ValidationException::withMessages(['run' => 'توجد عملية استيراد أخرى نشطة لهذا القانون.']);
            }
            $pages = $locked->pages()->whereIn('ai_status', ['failed', 'unavailable'])->get();
            if ($pages->isEmpty()) {
                throw ValidationException::withMessages(['run' => 'لا توجد صفحات AI لإعادة قراءتها.']);
            }
            $locked->update(['state' => 'reading', 'finished_at' => null]);
            foreach ($pages as $page) {
                $page->update(['ai_status' => 'pending', 'last_error' => null]);
                ReadLegalPageWithAi::dispatch($page->id, $locked->company_id)->afterCommit();
            }
            $this->audit->log('legal_import.ai_retry', $locked, ['pages' => $pages->pluck('page_number')->all()], $actor);
        });
    }

    public function cancel(LegalImportRun $run, User $actor, string $reason): void
    {
        abort_unless($actor->can('import', $run->law), 403);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => 'يلزم بيان سبب الإلغاء.']);
        }
        DB::transaction(function () use ($run, $actor, $reason): void {
            $locked = LegalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            if (in_array($locked->state, ['completed', 'failed', 'cancelled'], true)) {
                throw ValidationException::withMessages(['run' => 'انتهت عملية الاستيراد هذه بالفعل.']);
            }
            $locked->update(['state' => 'cancelled', 'finished_at' => now()]);
            $this->audit->log('legal_import.cancelled', $locked, ['reason' => $reason], $actor);
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
            throw ValidationException::withMessages(['source' => 'اختر ملف PDF ضمن الحد المسموح للحجم.']);
        }
        $count = $this->pdf->count($path);
        if ($from < 1 || $to < $from || $to > $count || $to - $from + 1 > (int) config('displanery.legal_library.max_pages', 300)) {
            throw ValidationException::withMessages(['page_from' => 'اختر نطاق صفحات صحيحاً.']);
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
