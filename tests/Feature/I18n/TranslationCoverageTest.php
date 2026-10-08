<?php

use Illuminate\Support\Facades\File;

test('workspace translation keys exist in both catalogue layers and UI code contains no Arabic prose', function (): void {
    $directories = [
        resource_path('views/layouts'), resource_path('views/livewire'),
        app_path('Livewire'), app_path('Services'), app_path('Exceptions'),
        app_path('Enums'), app_path('Models'), app_path('Http'),
    ];
    $paths = [
        resource_path('views/welcome.blade.php'),
        app_path('Support/Format/CaseTimeline.php'),
        app_path('Support/Format/Ui.php'),
    ];
    foreach ($directories as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $paths[] = $file->getPathname();
        }
    }

    $json = json_decode(File::get(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $violations = [];
    foreach ($paths as $path) {
        $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
        if ($relative === 'app/Services/Statistics/ReportBuilder.php') {
            continue; // Report rendering is localized with the export work.
        }
        $source = File::get($path);
        preg_match_all('/__\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
        foreach ($matches[1] as $key) {
            if (str_starts_with($key, 'messages.')) {
                $nested = rtrim(substr($key, strlen('messages.')), '.');
                if (data_get($english, $nested) === null || data_get($arabic, $nested) === null) {
                    $violations[] = $relative.': missing structured key '.$key;
                }
            } elseif (! str_contains($key, '::') && ! array_key_exists($key, $json)) {
                $violations[] = $relative.': missing JSON key '.$key;
            }
        }
        foreach (preg_split('/\R/u', $source) as $number => $line) {
            if (! preg_match('/\p{Arabic}/u', $line)) {
                continue;
            }
            $allowed = match ($relative) {
                'app/Services/Employees/CsvReader.php' => preg_match('/^\s*\'[a-z_]+\' => \[/', $line) === 1,
                'app/Services/Employees/EmployeeImport.php' => preg_match('/^\s*\'(?:male|female)\', \'.+\' =>/', $line) === 1,
                'app/Services/Documents/CitationSync.php' => str_contains($line, 'preg_split('),
                default => false,
            };
            if (! $allowed) {
                $violations[] = $relative.':'.($number + 1).': Arabic UI text';
            }
        }
    }

    expect($violations)->toBe([]);
});
