<?php

namespace App\LegalLibrary;

use App\Enums\Jurisdiction;
use App\Enums\LawApprovalStatus;
use App\Enums\LawKind;
use App\Models\Law;
use App\Models\LawJurisdiction;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LegalLibraryArchive
{
    private const VERSION = 1;

    public function __construct(private PdfPages $pdf, private TextNormalizer $normalizer, private AuditLogger $audit,
        private ?string $directory = null) {}

    public function export(string|array|null $number = null): int
    {
        return app(TenantContext::class)->runAcrossAllCompanies(function () use ($number): int {
            $laws = Law::query()->where('kind', LawKind::Statute)
                ->when($number !== null, fn ($query) => $query->whereIn('number', (array) $number))
                ->orderBy('year')->orderBy('number')->get();
            if ($laws->isEmpty()) {
                throw new RuntimeException('No platform law matches the export selection.');
            }
            $directory = $this->directory ?? database_path('legal-library');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $files = [];
            $payloads = [];
            foreach ($laws as $law) {
                $units = LegalUnit::query()->where('law_id', $law->id)->orderBy('id')->get();
                if ($units->isEmpty() || ! $units->whereNull('superseded_by_id')
                    ->contains(fn ($unit) => in_array($unit->status->value, ['verified_auto', 'verified_human'], true))
                    || $units->contains(fn ($unit) => in_array($unit->status->value, ['verified_text', 'verified_single'], true))
                    // Mismatches travel as mismatches (product owner 2026-10-16): import keeps them uncitable, so the
                    // review can continue on the receiving server instead of blocking the whole law.
                    || $units->whereNull('superseded_by_id')
                        ->contains(fn ($unit) => ! in_array($unit->status->value, ['verified_auto', 'verified_human', 'excluded', 'mismatch'], true))) {
                    throw new RuntimeException("Law {$law->number}/{$law->year} has unverified current units.");
                }
                $source = $this->sourcePath($law->source_file_path);
                $sha = hash_file('sha256', $source);
                if ($law->source_sha256 !== null && ! hash_equals($law->source_sha256, $sha)) {
                    throw new RuntimeException('Official source PDF hash differs from the law record.');
                }
                $data = ['schema_version' => self::VERSION, 'law' => [
                    'name' => $law->name, 'name_en' => $law->name_en, 'number' => $law->number,
                    'year' => $law->year, 'category' => $law->category,
                    'source_file_path' => $this->portableSourcePath($sha), 'source_sha256' => $sha,
                    'effective_from' => $law->effective_from, 'repealed_at' => $law->repealed_at,
                    'requires_page_range' => $law->requires_page_range,
                    'jurisdictions' => $law->jurisdictions->pluck('jurisdiction')->map(fn ($value) => $value->value)->sort()->values()->all(),
                ], 'units' => $units->map(fn ($unit) => [
                    'source_id' => $unit->id, 'version' => $unit->version,
                    'previous_source_id' => $unit->previous_version_id,
                    'superseded_source_id' => $unit->superseded_by_id,
                    'article_number' => $unit->article_number, 'clause_path' => $unit->clause_path,
                    'official_text' => $unit->official_text, 'source_page' => $unit->source_page,
                    'extraction_a' => $unit->extraction_a, 'extraction_b' => $unit->extraction_b,
                    'status' => $unit->status->value, 'verified_at' => $unit->verified_at,
                    'verified_by_source_id' => $unit->verified_by ?? ($unit->tags['library_export_verified_by'] ?? null),
                    'tags' => array_diff_key($unit->tags ?? [], ['library_export_verified_by' => true, 'library_source_id' => true]),
                ])->all()];
                $file = 'law-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($law->number.'-'.$law->year.'-'.$law->category)).'.json';
                $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
                $payloads[$file] = $json;
                $files[$file] = hash('sha256', $json);
            }
            ksort($files);
            foreach ($payloads as $file => $json) {
                file_put_contents($directory.'/'.$file, $json);
            }
            file_put_contents($directory.'/manifest.json', json_encode([
                'schema_version' => self::VERSION, 'files' => $files,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");

            return count($files);
        });
    }

    public function import(): int
    {
        $directory = $this->directory ?? database_path('legal-library');
        $manifest = $this->json($directory.'/manifest.json');
        if (($manifest['schema_version'] ?? null) !== self::VERSION || ! is_array($manifest['files'] ?? null)
            || $manifest['files'] === []) {
            throw new RuntimeException('Legal library manifest is invalid.');
        }
        $payloads = [];
        foreach ($manifest['files'] as $file => $sha) {
            if (! preg_match('/^law-[a-z0-9-]+\.json$/', $file) || ! is_string($sha)
                || ! preg_match('/^[a-f0-9]{64}$/', $sha)) {
                throw new RuntimeException('Legal library manifest entry is invalid.');
            }
            $path = $directory.'/'.$file;
            if (! is_file($path) || ! hash_equals($sha, hash_file('sha256', $path))) {
                throw new RuntimeException("Legal library hash mismatch: {$file}");
            }
            $data = $this->json($path);
            $law = $data['law'] ?? null;
            if (($data['schema_version'] ?? null) !== self::VERSION || ! is_array($law)
                || ! is_array($data['units'] ?? null) || $data['units'] === []
                || ($law['number'] ?? null) === null || ($law['year'] ?? null) === null
                || ! in_array($law['category'] ?? null, ['labor', 'social_security', 'other'], true)
                || ! preg_match('/^docs\/laws\/[a-z0-9-]+\.pdf$/', $law['source_file_path'] ?? '')
                || ! preg_match('/^[a-f0-9]{64}$/', $law['source_sha256'] ?? '')
                || ! is_array($law['jurisdictions'] ?? null)) {
                throw new RuntimeException("Legal library payload is invalid: {$file}");
            }
            $source = $this->sourcePath($law['source_file_path']);
            if (! hash_equals($law['source_sha256'] ?? '', hash_file('sha256', $source))) {
                throw new RuntimeException("Official source PDF hash mismatch: {$file}");
            }
            $pageCount = $this->pdf->count($source);
            foreach ($data['units'] as $unit) {
                if (! is_array($unit) || ! in_array($unit['status'] ?? null,
                    ['verified_auto', 'verified_human', 'excluded', 'mismatch', 'unverified'], true)
                    || ! is_int($unit['source_id'] ?? null) || ! is_int($unit['version'] ?? null)
                    || ! is_string($unit['article_number'] ?? null) || ! is_string($unit['official_text'] ?? null)) {
                    throw new RuntimeException("Legal library unit is invalid: {$file}");
                }
                if (in_array($unit['status'], ['verified_auto', 'verified_human'], true)
                    && (! is_int($unit['source_page'] ?? null) || $unit['source_page'] < 1
                        || empty($unit['verified_at']))) {
                    throw new RuntimeException("Verified legal unit lacks source provenance: {$file}");
                }
                if (($unit['source_page'] ?? null) !== null && (! is_int($unit['source_page'])
                    || $unit['source_page'] < 1 || $unit['source_page'] > $pageCount)) {
                    throw new RuntimeException("Legal unit page is outside the official PDF: {$file}");
                }
                if ($unit['status'] === 'verified_auto'
                    && (! is_string($unit['extraction_a'] ?? null) || ! is_string($unit['extraction_b'] ?? null)
                        || $this->normalizer->comparable($unit['extraction_a']) === ''
                        // The same rule ReadingComparator verified with; the stricter normalize() refused real units.
                        || $this->normalizer->comparable($unit['extraction_a']) !== $this->normalizer->comparable($unit['extraction_b']))) {
                    throw new RuntimeException("Automatically verified unit lacks two matching readings: {$file}");
                }
                if ($unit['status'] === 'verified_human' && ! is_int($unit['verified_by_source_id'] ?? null)) {
                    throw new RuntimeException("Human verified unit lacks reviewer provenance: {$file}");
                }
            }
            $payloads[] = $data;
        }

        return app(TenantContext::class)->runAcrossAllCompanies(fn (): int => DB::transaction(function () use ($payloads): int {
            foreach ($payloads as $data) {
                $this->importLaw($data);
            }

            return count($payloads);
        }));
    }

    private function importLaw(array $data): void
    {
        $meta = $data['law'];
        $law = Law::query()->where('kind', LawKind::Statute)->where('number', $meta['number'])
            ->where('year', $meta['year'])->where('category', $meta['category'])->lockForUpdate()->first();
        if ($law === null) {
            $law = Law::create(['company_id' => null, 'kind' => LawKind::Statute,
                'approval_status' => LawApprovalStatus::Approved, 'name' => $meta['name'],
                'name_en' => $meta['name_en'], 'number' => $meta['number'], 'year' => $meta['year'],
                'category' => $meta['category'], 'source_file_path' => $meta['source_file_path'],
                'source_sha256' => $meta['source_sha256'], 'effective_from' => $meta['effective_from'],
                'repealed_at' => $meta['repealed_at'], 'requires_page_range' => $meta['requires_page_range']]);
        } elseif ($law->source_sha256 !== null && ! hash_equals($law->source_sha256, $meta['source_sha256'])) {
            throw new RuntimeException('Existing law has a different official source PDF.');
        } elseif ($law->source_sha256 === null) {
            $law->update(['source_sha256' => $meta['source_sha256']]);
        }
        foreach ($meta['jurisdictions'] as $jurisdiction) {
            $value = Jurisdiction::from($jurisdiction);
            LawJurisdiction::firstOrCreate(['law_id' => $law->id, 'jurisdiction' => $value]);
        }
        $rows = collect($data['units'])->sortBy([['version', 'asc'], ['source_id', 'asc']])->values();
        $map = [];
        $run = null;
        foreach ($rows as $item) {
            // A law can print one article twice (original and amended), so article/clause/version is not an
            // identity: match the exported unit itself, by its recorded source id after an import, or by its own id
            // on the database that exported it.
            $unit = LegalUnit::query()->where('law_id', $law->id)
                ->where('article_number', $item['article_number'])->where('clause_path', $item['clause_path'])
                ->where('version', $item['version'])->get()
                ->first(fn (LegalUnit $candidate): bool => array_key_exists('library_source_id', $candidate->tags ?? [])
                    ? (int) $candidate->tags['library_source_id'] === (int) $item['source_id']
                    : $candidate->id === (int) $item['source_id']);
            $prior = ($item['previous_source_id'] ?? null) === null ? null : ($map[$item['previous_source_id']] ?? null);
            if (($item['previous_source_id'] ?? null) !== null && $prior === null) {
                throw new RuntimeException('Legal unit version chain is incomplete.');
            }
            $attributes = ['law_id' => $law->id, 'company_id' => null, 'import_run_id' => null,
                'version' => $item['version'], 'previous_version_id' => $prior,
                'article_number' => $item['article_number'], 'clause_path' => $item['clause_path'],
                'official_text' => $item['official_text'], 'source_page' => $item['source_page'],
                'extraction_a' => $item['extraction_a'], 'extraction_b' => $item['extraction_b'],
                'status' => $item['status'], 'verified_by' => null,
                'verified_at' => $item['verified_at'], 'tags' => [...($item['tags'] ?? []),
                    'library_export_verified_by' => $item['verified_by_source_id'], 'library_source_id' => $item['source_id']]];
            if ($unit !== null) {
                foreach (['previous_version_id', 'official_text', 'source_page', 'extraction_a', 'extraction_b', 'status'] as $field) {
                    $actual = $field === 'status' ? $unit->status->value : $unit->{$field};
                    if ($actual != $attributes[$field]) {
                        throw new RuntimeException('Existing legal unit conflicts with library archive.');
                    }
                }
                $tags = $unit->tags ?? [];
                $importedVerifier = $tags['library_export_verified_by'] ?? null;
                unset($tags['library_export_verified_by'], $tags['library_source_id']);
                if ($unit->company_id !== null || $tags != ($item['tags'] ?? [])
                    || ($unit->verified_by !== null && (int) $unit->verified_by !== (int) ($item['verified_by_source_id'] ?? 0))
                    || ($unit->verified_by === null && $importedVerifier != ($item['verified_by_source_id'] ?? null))
                    || ($unit->verified_at === null) !== (($item['verified_at'] ?? null) === null)
                    || ($unit->verified_at !== null && strtotime((string) $unit->verified_at) !== strtotime((string) $item['verified_at']))) {
                    throw new RuntimeException('Existing legal unit provenance conflicts with library archive.');
                }
            } else {
                $run ??= $this->newRun($law, $meta,
                    (int) $rows->pluck('source_page')->filter()->min(),
                    (int) $rows->pluck('source_page')->filter()->max());
                $attributes['import_run_id'] = $run->id;
                $unit = LegalUnit::create($attributes);
                $this->audit->log('legal_unit.library_loaded', $unit,
                    ['source_unit_id' => $item['source_id'], 'source_sha256' => $meta['source_sha256']]);
            }
            $map[$item['source_id']] = $unit->id;
        }
        foreach ($rows as $item) {
            if (($item['superseded_source_id'] ?? null) === null) {
                continue;
            }
            $unit = LegalUnit::query()->findOrFail($map[$item['source_id']]);
            $target = $map[$item['superseded_source_id']] ?? null;
            if ($target === null) {
                throw new RuntimeException('Legal unit supersession chain is incomplete.');
            }
            if ($unit->superseded_by_id === null) {
                $unit->update(['superseded_by_id' => $target]);
            } elseif ((int) $unit->superseded_by_id !== (int) $target) {
                throw new RuntimeException('Existing legal unit supersession conflicts with archive.');
            }
        }
        if ($run !== null) {
            $this->renderPages($run, $rows->pluck('source_page')->filter()->unique()->all());
        }
    }

    private function newRun(Law $law, array $meta, int $firstPage, int $lastPage): LegalImportRun
    {
        $source = $this->sourcePath($meta['source_file_path']);
        $relative = 'legal-library/sources/'.$meta['source_sha256'].'.pdf';
        if (! Storage::disk('local')->exists($relative)) {
            Storage::disk('local')->put($relative, file_get_contents($source));
        }

        $run = LegalImportRun::create(['law_id' => $law->id, 'company_id' => null,
            'source_path' => $relative, 'source_sha256' => $meta['source_sha256'],
            'page_from' => $firstPage, 'page_to' => $lastPage, 'mode' => 'library',
            'state' => 'completed', 'started_by' => null, 'started_at' => now(), 'finished_at' => now()]);
        $this->audit->log('legal_import.library_loaded', $run, ['source_sha256' => $meta['source_sha256']]);

        return $run;
    }

    private function renderPages(LegalImportRun $run, array $pageNumbers): void
    {
        $pdf = Storage::disk('local')->path($run->source_path);
        foreach ($pageNumbers as $number) {
            $relative = "legal-library/runs/{$run->id}/pages/{$number}.png";
            $prefix = substr(Storage::disk('local')->path($relative), 0, -4);
            if (! is_dir(dirname($prefix))) {
                mkdir(dirname($prefix), 0700, true);
            }
            $image = $this->pdf->render($pdf, (int) $number, $prefix);
            LegalImportPage::create(['run_id' => $run->id, 'company_id' => null,
                'page_number' => $number, 'image_path' => $relative, 'image_sha256' => hash_file('sha256', $image),
                'text_status' => 'done', 'text_method' => 'library', 'ai_status' => 'done', 'ai_source' => 'library']);
        }
    }

    private function sourcePath(string $path): string
    {
        $source = is_file(base_path($path)) ? base_path($path) : Storage::disk('local')->path($path);
        if (! is_file($source) || strtolower(pathinfo($source, PATHINFO_EXTENSION)) !== 'pdf') {
            throw new RuntimeException('Official source PDF is missing.');
        }

        return $source;
    }

    private function portableSourcePath(string $sha): string
    {
        foreach (glob(base_path('docs/laws/*.pdf')) ?: [] as $candidate) {
            if (hash_equals($sha, hash_file('sha256', $candidate))) {
                return 'docs/laws/'.basename($candidate);
            }
        }

        throw new RuntimeException('The official source PDF is not shipped under docs/laws.');
    }

    private function json(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Legal library JSON file is missing.');
        }
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('Legal library JSON file is invalid.');
        }

        return $data;
    }
}
