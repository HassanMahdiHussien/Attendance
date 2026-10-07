<?php

namespace App\LegalLibrary;

use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

class UnitBuilder
{
    public function __construct(private ArticleSplitter $splitter, private ReadingComparator $comparator,
        private TextNormalizer $normalizer, private AuditLogger $audit) {}

    public function build(LegalImportRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $run = LegalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->state !== 'splitting') {
                return;
            }
            $pages = $run->pages()->orderBy('page_number')->get();
            $readingA = $pages->filter(fn ($page) => $page->text_status === 'done')->pluck('text', 'page_number')->all();
            $readingB = $pages->filter(fn ($page) => $page->ai_status === 'done')->pluck('ai_text', 'page_number')->all();
            $units = $this->comparator->compare($this->splitter->split($readingA), $this->splitter->split($readingB));
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
                $lastPage = max([$unit['page'], ...($unit['tags']['continues_on'] ?? [])]);
                $pagesTouched = range($unit['page'], $lastPage);
                // A failed neighboring page may contain an unseen continuation of this unit.
                if ($pages->whereIn('page_number', $pagesTouched)->contains(fn ($page) => $page->text_status !== 'done' || $page->ai_status !== 'done')
                    || collect($failedPages)->contains(fn ($failed) => abs($failed - $unit['page']) === 1)) {
                    $unit['status'] = 'mismatch';
                    $unit['tags']['mismatch_reason'] = 'page_failed';
                } elseif ($pages->whereIn('page_number', $pagesTouched)->contains(fn ($page) => $page->ai_illegible)) {
                    $unit['status'] = 'mismatch';
                    $unit['tags']['mismatch_reason'] = 'illegible';
                }
                // Both readings agree after normalisation for a verified unit; the AI transcription keeps
                // natural spacing and bracket order, which text layers often mangle ("()2", "أولا -نص").
                $text = $unit['status'] === 'verified_auto'
                    ? ($unit['extraction_b'] ?? $unit['extraction_a'] ?? '')
                    : ($unit['extraction_a'] ?? $unit['extraction_b'] ?? '');
                $text = trim(preg_replace(['/[ \t]+/u', '/ *\R */u'], [' ', "\n"], $text));
                if ($existing && in_array($existing->status->value,
                    ['verified_auto', 'verified_human', 'verified_text', 'excluded'], true)
                    && $this->normalizer->normalize($existing->official_text) === $this->normalizer->normalize($text)) {
                    continue;
                }
                $created = LegalUnit::create(['law_id' => $run->law_id, 'company_id' => $run->company_id,
                    'import_run_id' => $run->id, 'version' => $existing ? $existing->version + 1 : 1,
                    'previous_version_id' => $existing?->id, 'article_number' => $unit['article_number'],
                    'clause_path' => $unit['clause_path'], 'official_text' => $text, 'source_page' => $unit['page'],
                    'extraction_a' => $unit['extraction_a'], 'extraction_b' => $unit['extraction_b'],
                    'status' => $unit['status'], 'verified_at' => $unit['status'] === 'verified_auto' ? now() : null,
                    'tags' => $unit['tags']]);
                if ($unit['status'] === 'verified_auto') {
                    $this->audit->log('legal_unit.verified', $created,
                        ['method' => 'two_readings', 'import_run_id' => $run->id]);
                }
                if ($existing && $unit['status'] === 'verified_auto') {
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
                'mismatch' => collect($units)->where('status', 'mismatch')->count(),
                'failed_pages' => $pages->filter(fn ($page) => $page->text_status === 'failed' || $page->ai_status === 'failed')->count(),
            ]]);
            $this->audit->log('legal_import.completed', $run);
        });
    }
}
