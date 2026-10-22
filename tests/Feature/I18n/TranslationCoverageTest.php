<?php

use App\Services\Employees\EmployeeHistory;
use Illuminate\Support\Facades\File;

test('all UI translation keys exist in both catalogue layers and UI code contains no Arabic prose', function (): void {
    $paths = [];
    foreach ([app_path(), resource_path('views')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
            $paths[] = $file->getPathname();
        }
    }

    $json = json_decode(File::get(lang_path('ar.json')), true, flags: JSON_THROW_ON_ERROR);
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));
    $violations = [];
    foreach ($paths as $path) {
        $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
        $source = File::get($path);
        preg_match_all('/(?:__|trans|@lang)\(\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
        foreach ($matches[1] as $key) {
            $group = str_contains($key, '.') ? strstr($key, '.', true) : '';
            if ($group !== '' && preg_match('/^[a-z_]+$/', $group) && is_file(lang_path("en/{$group}.php"))) {
                // Structured groups must exist in both languages.
                $nested = rtrim(substr($key, strlen($group) + 1), '.');
                $englishGroup = $group === 'messages' ? $english : File::getRequire(lang_path("en/{$group}.php"));
                $arabicGroup = $group === 'messages' ? $arabic
                    : (is_file(lang_path("ar/{$group}.php")) ? File::getRequire(lang_path("ar/{$group}.php")) : []);
                if (data_get($englishGroup, $nested) === null || data_get($arabicGroup, $nested) === null) {
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
                'app/Support/Text/ArabicText.php', 'app/Support/Search/SearchText.php' => str_contains($line, 'strtr('),
                'app/Services/Employees/CsvReader.php' => preg_match('/^\s*\'[a-z_]+\' => \[/', $line) === 1,
                'app/Services/Employees/EmployeeImport.php' => preg_match('/^\s*\'(?:male|female)\', \'.+\' =>/', $line) === 1,
                default => false,
            };
            if (! $allowed) {
                $violations[] = $relative.':'.($number + 1).': Arabic UI text';
            }
        }
    }

    expect($violations)->toBe([]);
});

test('dynamic employee history labels exist in both languages', function (): void {
    $english = File::getRequire(lang_path('en/messages.php'));
    $arabic = File::getRequire(lang_path('ar/messages.php'));

    foreach (EmployeeHistory::FIELDS as $field) {
        expect(data_get($english, 'employee_history.fields.'.$field))->not->toBeNull()
            ->and(data_get($arabic, 'employee_history.fields.'.$field))->not->toBeNull();
    }
    foreach (['initial', 'manual', 'import', 'chat'] as $source) {
        expect(data_get($english, 'employee_history.sources.'.$source))->not->toBeNull()
            ->and(data_get($arabic, 'employee_history.sources.'.$source))->not->toBeNull();
    }
});
