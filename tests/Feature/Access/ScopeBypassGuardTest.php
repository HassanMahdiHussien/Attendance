<?php

test('company scope bypasses stay in the reviewed access path', function (): void {
    $offenders = [];
    $root = base_path('app');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
        if (str_contains(file_get_contents($file->getPathname()), 'withoutGlobalScope(CompanyScope')) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});
