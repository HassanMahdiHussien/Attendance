<?php

use App\LegalLibrary\AiUnavailable;
use App\LegalLibrary\ClaudePageReader;
use App\Models\AiGenerationLog;
use Illuminate\Support\Facades\Storage;

test('missing API key makes no request and records only image hash metadata', function (): void {
    config()->set('services.anthropic.key', '');
    Storage::disk('local')->put('legal-library/test.png', 'fixture bytes');
    $path = Storage::disk('local')->path('legal-library/test.png');
    expect(fn () => (new ClaudePageReader)->read($path,
        ['law_id' => 1, 'run_id' => 2, 'page' => 3]))->toThrow(AiUnavailable::class);
    $log = AiGenerationLog::query()->firstOrFail();
    expect($log->touchpoint)->toBe('legal_library_reading');
    expect($log->input)->toContain('image_sha256')->not->toContain('fixture bytes');
});

test('legal page reading defaults to Sonnet at low effort without changing drafting defaults', function (): void {
    expect(config('displanery.ai.legal_reading_model'))->toBe('claude-sonnet-5-5')
        ->and(config('displanery.ai.legal_reading_effort'))->toBe('low')
        ->and(config('displanery.ai.drafting_model'))->toBe('claude-opus-5-5');
});
