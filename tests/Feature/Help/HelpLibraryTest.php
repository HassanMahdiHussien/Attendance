<?php

use App\Enums\UserRole;
use App\Support\Help\HelpLibrary;
use Illuminate\Support\Facades\File;

test('English and Arabic guides have matching paths and valid references', function (): void {
    $library = app(HelpLibrary::class);
    $english = $library->all('en');
    $arabic = $library->all('ar');
    $paths = fn (array $articles): array => array_map(fn ($article): string => $article->section.'/'.$article->slug, $articles);
    expect($paths($english))->toEqual($paths($arabic));
    $manifest = require resource_path('help/screenshots.php');
    foreach (['en' => $english, 'ar' => $arabic] as $locale => $articles) {
        foreach ($articles as $article) {
            expect($article->title)->not->toBeEmpty()
                ->and($article->summary)->not->toBeEmpty()
                ->and($article->order)->toBeGreaterThanOrEqual(0);
            foreach ($article->audience as $audience) {
                expect($audience === 'all' || UserRole::tryFrom($audience) !== null)->toBeTrue();
            }
            preg_match_all('/\]\(help:([a-z0-9-]+)\/([a-z0-9-]+)\)/', $article->markdown, $links, PREG_SET_ORDER);
            foreach ($links as $link) {
                expect($library->find($locale, $link[1], $link[2]))->not->toBeNull();
            }
            preg_match_all('/!\[[^\]]*\]\(shots\/([a-z0-9-]+)\)/', $article->markdown, $shots, PREG_SET_ORDER);
            foreach ($shots as $shot) {
                expect($manifest)->toHaveKey($shot[1]);
                foreach (['en', 'ar'] as $imageLocale) {
                    expect(File::exists(resource_path("help/shots/{$imageLocale}/{$shot[1]}.webp")))->toBeTrue();
                }
            }
        }
    }
});

test('unsafe markdown is escaped and help links are rewritten before rendering', function (): void {
    $root = storage_path('framework/testing/help-'.uniqid());
    File::ensureDirectoryExists($root.'/en/getting-started');
    try {
        File::put($root.'/en/getting-started/test.md', "---\ntitle: Test\nsummary: Safe\naudience: all\norder: 1\n---\n<script>alert(1)</script> [bad](javascript:alert(1)) [good](help:getting-started/test)");
        $article = (new HelpLibrary($root))->find('en', 'getting-started', 'test');
        expect($article->html)->toContain('&lt;script&gt;')->not->toContain('<script>')->not->toContain('href="javascript:')->toContain('/help/getting-started/test');
    } finally {
        File::deleteDirectory($root);
    }
});

test('help library reflects file additions edits and deletions', function (): void {
    $root = storage_path('framework/testing/help-'.uniqid());
    $directory = $root.'/en/getting-started';
    File::ensureDirectoryExists($directory);
    $library = new HelpLibrary($root);
    $file = $directory.'/new.md';
    try {
        expect($library->all('en'))->toBe([]);
        File::put($file, "---\ntitle: First\nsummary: One\naudience: all\norder: 1\n---\nBody");
        expect($library->find('en', 'getting-started', 'new')->title)->toBe('First');
        File::put($file, "---\ntitle: Second\nsummary: Two\naudience: all\norder: 1\n---\nBody");
        expect($library->find('en', 'getting-started', 'new')->title)->toBe('Second');
        File::delete($file);
        expect($library->find('en', 'getting-started', 'new'))->toBeNull();
    } finally {
        File::deleteDirectory($root);
    }
});

test('search matches English and normalized Arabic terms', function (): void {
    $library = app(HelpLibrary::class);
    expect($library->search('en', 'disciplinary'))->not->toBeEmpty()
        ->and($library->search('ar', 'الاجراءات'))->not->toBeEmpty();
});
