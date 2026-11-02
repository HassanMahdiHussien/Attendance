<?php

use Illuminate\Support\Facades\File;

// Livewire's browser script maps these names to its own magic actions before the request is sent, so a component
// method with the same name passes Livewire::test()->call() but never runs on a real click.
test('views never bind an action to a name Livewire reserves', function (): void {
    $reserved = ['get', 'el', 'id', 'js', 'set', 'refs', 'call', 'hook', 'watch', 'dirty', 'effect', 'commit', 'errors', 'island', 'upload',
        'entangle', 'dispatch', 'intercept', 'interceptAction', 'interceptMessage', 'interceptRequest', 'dispatchTo', 'dispatchSelf',
        'dispatchEl', 'dispatchRef', 'removeUpload', 'cancelUpload', 'uploadMultiple'];
    $pattern = '/wire:(?:click|submit|change|keydown)[\w.-]*="('.implode('|', $reserved).')(?:\(|")/';

    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => preg_match($pattern, File::get($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => $file->getPathname())
        ->values()->all();

    expect($offenders)->toBe([]);
});
