<?php

namespace App\LegalLibrary;

use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class UnitBuilder
{
    public function __construct(private ArticleSplitter $splitter, private ReadingComparator $comparator,
        private TextNormalizer $normalizer, private AuditLogger $audit) {}

    public function build(LegalImportRun $run): void
    {
        $secondaryText = null;
        if ($run->secondary_source_path !== null) {
            $source = Storage::disk('local')->path($run->secondary_source_path);
            if (! hash_equals($run->secondary_source_sha256, hash_file('sha256', $source))) {
                throw new \RuntimeException('Stored secondary source hash does not match.');
            }
            $text = strtolower(pathinfo($source, PATHINFO_EXTENSION)) === 'pdf'
                ? $this->pdfText($source) : file_get_contents($source);
            if (! mb_check_encoding($text, 'UTF-8') || trim($text) === '') {
                throw new \RuntimeException('Secondary source has no readable text.');
            }
            $secondaryText = app(WordLigatureRepair::class)->repair($text);
        }
        DB::transaction(function () use ($run, $secondaryText): void {
            $run = LegalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->state !== 'splitting') {
                return;
            }
            $pages = $run->pages()->orderBy('page_number')->get();
            $furniture = app(PageFurniture::class);
            $readingA = $furniture->strip($pages->filter(fn ($page) => $page->text_status === 'done')->pluck('text', 'page_number')->all());
            $readingB = $furniture->strip($pages->filter(fn ($page) => $page->ai_status === 'done')->pluck('ai_text', 'page_number')->all());
            if ($secondaryText !== null) {
                $readingB = [0 => $secondaryText];
            }
            $units = $this->comparator->compare($this->splitter->split($readingA), $this->splitter->split($readingB));
            foreach ($units as &$compared) {
                if ($run->secondary_source_path !== null && $compared['extraction_a'] === null) {
                    $compared['page'] = null;
                }
                $sourcePage = $pages->firstWhere('page_number', $compared['page']);
                $compared['tags']['reading_a_source'] = $sourcePage?->text_method;
                $compared['tags']['reading_b_source'] = $run->secondary_source_path === null
                    ? $sourcePage?->ai_source : 'secondary_text';
                $compared['tags']['reading_a_uploaded_by'] = $sourcePage?->text_method === 'transcription'
                    ? $run->transcription_uploaded_by : null;
                $compared['tags']['reading_b_uploaded_by'] = $run->secondary_source_path === null
                    ? null : $run->secondary_source_uploaded_by;
            }
            unset($compared);
            $failedPages = $pages->filter(fn ($page) => $page->text_status === 'failed' || $page->ai_status === 'failed')
                ->pluck('page_number')->all();
            $previous = LegalUnit::query()->where('law_id', $run->law_id)->whereNull('superseded_by_id')
                ->where(fn ($query) => $query->whereNull('import_run_id')->orWhere('import_run_id', '!=', $run->id))
                ->get()->keyBy(fn ($unit) => $unit->article_number.'|'.$unit->clause_path);
            $seen = [];
            foreach ($units as &$unit) {
                $key = $unit['article_number'].'|'.$unit['clause_path'];
                $seen[$key] = true;
                $existing = $previous->get($key);
                $lastPage = max([$unit['page'] ?? 0, ...($unit['tags']['continues_on'] ?? [])]);
                $pagesTouched = $unit['page'] === null ? [] : range($unit['page'], $lastPage);
                $touched = $pages->whereIn('page_number', $pagesTouched);
                $single = $run->law->kind->value === 'internal_regulation'
                    && $run->secondary_source_path === null && $pagesTouched !== []
                    && $touched->count() === count($pagesTouched)
                    && $touched->every(fn ($page) => $page->text_status === 'done' && $page->ai_status === 'skipped');
                $mixed = $run->law->kind->value === 'internal_regulation' && $touched->contains(
                    fn ($page) => $page->ai_status === 'skipped') && ! $single;
                if ($single && $unit['extraction_a'] !== null
                    && ($unit['tags']['mismatch_reason'] ?? null) === 'one_reading_only') {
                    $unit['status'] = 'verified_single';
                    unset($unit['tags']['mismatch_reason']);
                } elseif ($mixed) {
                    $unit['status'] = 'mismatch';
                    $unit['tags']['mismatch_reason'] = 'partial_single_reading';
                }
                // A failed neighboring page may contain an unseen continuation of this unit.
                if ($touched->contains(fn ($page) => $page->text_status !== 'done' || ! in_array($page->ai_status, ['done', 'skipped'], true))
                    || collect($failedPages)->contains(fn ($failed) => abs($failed - $unit['page']) === 1)) {
                    $unit['status'] = 'mismatch';
                    $unit['tags']['mismatch_reason'] = 'page_failed';
                } elseif ($pages->whereIn('page_number', $pagesTouched)->contains(fn ($page) => $page->ai_illegible)) {
                    $unit['status'] = 'mismatch';
                    $unit['tags']['mismatch_reason'] = 'illegible';
                }
                // Both readings agree after normalisation for a verified unit; the AI transcription keeps
                // natural spacing and bracket order, which text layers often mangle ("()2", "أولا -نص").
                $text = $unit['status'] === 'verified_auto' && $run->secondary_source_path === null
                    ? ($unit['extraction_b'] ?? $unit['extraction_a'] ?? '')
                    : ($unit['extraction_a'] ?? $unit['extraction_b'] ?? '');
                $text = trim(preg_replace(['/[ \t]+/u', '/ *\R */u'], [' ', "\n"], $text));
                if ($existing && in_array($existing->status->value,
                    ['verified_auto', 'verified_human', 'verified_text', 'verified_single', 'excluded'], true)
                    && ! ($existing->status->value === 'verified_single' && $existing->import_run_id !== $run->id)
                    && $this->normalizer->normalize($existing->official_text) === $this->normalizer->normalize($text)) {
                    continue;
                }
                $created = LegalUnit::create(['law_id' => $run->law_id, 'company_id' => $run->company_id,
                    'import_run_id' => $run->id, 'version' => $existing ? $existing->version + 1 : 1,
                    'previous_version_id' => $existing?->id, 'article_number' => $unit['article_number'],
                    'clause_path' => $unit['clause_path'], 'official_text' => $text, 'source_page' => $unit['page'],
                    'extraction_a' => $unit['extraction_a'], 'extraction_b' => $unit['extraction_b'],
                    'status' => $unit['status'], 'verified_at' => in_array($unit['status'], ['verified_auto', 'verified_single'], true) ? now() : null,
                    'tags' => $unit['tags']]);
                if ($unit['status'] === 'verified_auto') {
                    $this->audit->log('legal_unit.verified', $created,
                        ['method' => 'two_readings', 'import_run_id' => $run->id]);
                }
                if ($unit['status'] === 'verified_single') {
                    $this->audit->log('legal_unit.verified', $created,
                        ['method' => 'single_reading', 'import_run_id' => $run->id]);
                }
                if ($existing && in_array($unit['status'], ['verified_auto', 'verified_single'], true)) {
                    $existing->update(['superseded_by_id' => $created->id]);
                } elseif ($existing && in_array($existing->status->value, ['unverified', 'mismatch'], true)) {
                    $existing->update(['status' => 'excluded', 'tags' => [...($existing->tags ?? []),
                        'excluded_reason' => 'replaced_by_run:'.$run->id]]);
                    $this->audit->log('legal_unit.excluded', $existing);
                }
            }
            unset($unit);
            foreach ($previous as $key => $old) {
                if (! isset($seen[$key]) && in_array($old->status->value, ['unverified', 'mismatch'], true)) {
                    $old->update(['status' => 'excluded', 'tags' => [...($old->tags ?? []), 'excluded_reason' => 'replaced_by_run:'.$run->id]]);
                    $this->audit->log('legal_unit.excluded', $old);
                }
            }
            $run->update(['state' => 'completed', 'finished_at' => now(), 'counts' => [
                'pages' => $pages->count(), 'units' => count($units),
                'verified_auto' => collect($units)->where('status', 'verified_auto')->count(),
                'verified_single' => collect($units)->where('status', 'verified_single')->count(),
                'mismatch' => collect($units)->where('status', 'mismatch')->count(),
                'local_pages' => $pages->where('ai_status', 'skipped')->count(),
                'ai_pages' => $pages->filter(fn ($page) => in_array($page->ai_source, ['api', 'api_batch'], true))->count(),
                'failed_pages' => $pages->filter(fn ($page) => $page->text_status === 'failed' || $page->ai_status === 'failed')->count(),
                ...($pages->contains(fn ($page): bool => $page->text_repairs !== null) ? [
                    'text_repair_mirrored_brackets' => $pages->sum(fn ($page): int => $page->text_repairs['mirrored_brackets'] ?? 0),
                    'text_repair_kaf_from_alef_madda' => $pages->sum(fn ($page): int => $page->text_repairs['kaf_from_alef_madda'] ?? 0),
                ] : []),
            ]]);
            $this->audit->log('legal_import.completed', $run);
        });
    }

    private function pdfText(string $path): string
    {
        $pdf = app(PdfPages::class);
        $text = '';
        for ($page = 1; $page <= $pdf->count($path); $page++) {
            $text .= "\n".$pdf->text($path, $page);
        }

        return $text;
    }
}
