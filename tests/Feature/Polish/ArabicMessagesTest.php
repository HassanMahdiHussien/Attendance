<?php

use Illuminate\Support\Facades\File;

test('user-facing blocking and validation messages begin in Arabic', function (): void {
    $violations = [];
    foreach (File::allFiles(app_path()) as $file) {
        $source = File::get($file->getPathname());
        preg_match_all('/throw new (?:TransitionBlocked|IssuanceBlocked)\(\s*([\'\"])(.*?)\1/s', $source, $blocked, PREG_SET_ORDER);
        preg_match_all('/withMessages\(\s*\[\s*(?:\'[^\']+\'|\"[^\"]+\"|\$[A-Za-z_]\w*)\s*=>\s*([\'\"])(.*?)\1/s', $source, $validation, PREG_SET_ORDER);
        foreach (array_merge($blocked, $validation) as $match) {
            $message = preg_replace('/\{\$[^}]+\}|\b(?:PDF|PNG|JPG|JPEG|AI|MB|logo_text|image)\b/', '', $match[2]);
            if (preg_match('/[A-Za-z]{3,}/', $message)) {
                $violations[] = $file->getRelativePathname().': '.$match[2];
            }
        }
    }

    expect($violations)->toBe([]);
});
