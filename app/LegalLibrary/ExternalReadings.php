<?php

namespace App\LegalLibrary;

use App\Models\LegalImportRun;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExternalReadings
{
    public function __construct(private AuditLogger $audit) {}

    public function attach(LegalImportRun $run, User $actor, ?UploadedFile $transcription, ?UploadedFile $secondary,
        ?string $transcriptionNote = null, ?string $secondaryNote = null): void
    {
        abort_unless($actor->can('import', $run->law), 403);
        if ($run->state !== 'queued') {
            throw ValidationException::withMessages(['run' => __('messages.legal.import_still_active')]);
        }
        if ($transcription === null && $secondary === null) {
            return;
        }

        $attributes = [];
        if ($transcription !== null) {
            $this->validateFile($transcription, ['json'], 'transcription');
            $contents = file_get_contents($transcription->getRealPath());
            $this->decodePages($contents, $run);
            $fileNote = json_decode($contents, true)['note'] ?? null;
            if ($fileNote !== null && ! is_string($fileNote)) {
                throw ValidationException::withMessages(['transcription' => __('messages.legal.source_type_unsupported')]);
            }
            $transcriptionNote ??= $fileNote;
            $sha = hash_file('sha256', $transcription->getRealPath());
            $attributes += [
                'transcription_path' => $this->store($transcription, $sha, 'json'),
                'transcription_sha256' => $sha,
                'transcription_uploaded_by' => $actor->id,
                'transcription_uploaded_at' => now(),
                'transcription_note' => $transcriptionNote === null ? null : mb_substr(trim($transcriptionNote), 0, 255),
            ];
        }
        if ($secondary !== null) {
            $this->validateFile($secondary, ['pdf', 'txt'], 'secondary');
            $sha = hash_file('sha256', $secondary->getRealPath());
            if ($sha === $run->source_sha256 || $sha === ($attributes['transcription_sha256'] ?? $run->transcription_sha256)) {
                throw ValidationException::withMessages(['secondary' => __('messages.legal.source_hash_mismatch')]);
            }
            if (strtolower($secondary->getClientOriginalExtension()) === 'txt') {
                $text = file_get_contents($secondary->getRealPath());
                if (! mb_check_encoding($text, 'UTF-8') || trim($text) === '') {
                    throw ValidationException::withMessages(['secondary' => __('messages.legal.source_type_unsupported')]);
                }
            }
            $attributes += [
                'secondary_source_path' => $this->store($secondary, $sha, strtolower($secondary->getClientOriginalExtension())),
                'secondary_source_sha256' => $sha,
                'secondary_source_uploaded_by' => $actor->id,
                'secondary_source_uploaded_at' => now(),
                'secondary_source_note' => $secondaryNote === null ? null : mb_substr(trim($secondaryNote), 0, 255),
            ];
        }
        $run->update($attributes);
        $this->audit->log('legal_import.external_readings_attached', $run, [
            'transcription_sha256' => $attributes['transcription_sha256'] ?? null,
            'secondary_source_sha256' => $attributes['secondary_source_sha256'] ?? null,
        ], $actor);
    }

    public function pageTexts(LegalImportRun $run): array
    {
        if ($run->transcription_path === null) {
            return [];
        }
        $path = Storage::disk('local')->path($run->transcription_path);
        if (! hash_equals($run->transcription_sha256, hash_file('sha256', $path))) {
            throw new \RuntimeException('Stored transcription hash does not match.');
        }

        return $this->decodePages(file_get_contents($path), $run);
    }

    private function decodePages(string $json, LegalImportRun $run): array
    {
        $data = json_decode($json, true);
        $pages = $data['pages'] ?? null;
        if (! mb_check_encoding($json, 'UTF-8') || ! is_array($pages) || array_is_list($pages) || $pages === []) {
            throw ValidationException::withMessages(['transcription' => __('messages.legal.source_type_unsupported')]);
        }
        $result = [];
        foreach ($pages as $number => $text) {
            if (! ctype_digit((string) $number) || (int) $number < $run->page_from || (int) $number > $run->page_to
                || ! is_string($text) || trim($text) === '') {
                throw ValidationException::withMessages(['transcription' => __('messages.legal.page_range_invalid')]);
            }
            $result[(int) $number] = $text;
        }

        return $result;
    }

    private function validateFile(UploadedFile $file, array $extensions, string $field): void
    {
        if (! $file->isValid() || ! in_array(strtolower($file->getClientOriginalExtension()), $extensions, true)
            || $file->getSize() > (int) config('displanery.legal_library.max_file_mb', 50) * 1024 * 1024) {
            throw ValidationException::withMessages([$field => __('messages.legal.source_type_unsupported')]);
        }
    }

    private function store(UploadedFile $file, string $sha, string $extension): string
    {
        $relative = "legal-library/readings/{$sha}.{$extension}";
        if (! Storage::disk('local')->exists($relative)) {
            Storage::disk('local')->put($relative, file_get_contents($file->getRealPath()));
        } elseif (! hash_equals($sha, hash_file('sha256', Storage::disk('local')->path($relative)))) {
            throw new \RuntimeException('Stored reading hash does not match.');
        }

        return $relative;
    }
}
