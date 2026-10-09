<?php

use Illuminate\Support\Facades\File;

test('all UI translation keys exist in both catalogue layers and UI code contains no Arabic prose', function (): void {
    $paths = [];
    foreach ([app_path(), resource_path('views')] as $directory) {
        foreach (File::allFiles($directory) as $file) {
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
            if (str_starts_with($relative, 'app/Documents/') || str_starts_with($relative, 'resources/views/documents/')) {
                continue;
            }
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
                'app/Filament/Admin/Pages/DocumentTemplates.php' => str_contains($line, '$facts['),
                'app/Support/Format/ArabicDigits.php', 'app/Support/Format/OutcomeLabel.php',
                'app/LegalLibrary/ArticleSplitter.php', 'app/LegalLibrary/ClaudePageReader.php',
                'app/LegalLibrary/TextLayerProbe.php', 'app/LegalLibrary/TextNormalizer.php',
                'app/LegalLibrary/UnitBuilder.php' => true,
                'app/Support/Text/ArabicText.php', 'app/Support/Search/SearchText.php' => str_contains($line, 'strtr('),
                'app/Console/Commands/SearchBenchmark.php' => str_contains($line, 'أحمد') || str_contains($line, 'احمد'),
                'app/Ai/CandidateUnits.php' => str_contains($line, 'array_diff('),
                'app/Ai/DraftValidator.php' => str_contains($line, 'preg_match(') || str_contains($line, 'str_contains('),
                'app/Ai/OutcomeKeywordFlag.php' => str_contains($line, '=>'),
                'app/Ai/PayloadBuilder.php' => str_contains($line, '$add(') || str_contains($line, 'preg_replace(')
                    || str_contains($line, '// Whole words only'),
                'app/Services/Employees/CsvReader.php' => preg_match('/^\s*\'[a-z_]+\' => \[/', $line) === 1,
                'app/Services/Employees/EmployeeImport.php' => preg_match('/^\s*\'(?:male|female)\', \'.+\' =>/', $line) === 1,
                'app/Services/Documents/CitationSync.php' => str_contains($line, 'preg_split('),
                // These Arabic characters are input tokens and AI instructions, not interface copy.
                'app/Filament/Platform/Resources/LawResource/RelationManagers/UnitsRelationManager.php' => str_contains($line, 'preg_split(') && str_contains($line, 'topics'),
                'app/Jobs/TranscribeResponseScan.php' => str_contains($line, 'Mark unreadable words') && str_contains($line, "'text' =>"),
                'app/LegalLibrary/ReadingComparator.php' => str_contains($line, 'str_contains(') && str_contains($line, 'illegible'),
                default => false,
            };
            if (! $allowed) {
                $violations[] = $relative.':'.($number + 1).': Arabic UI text';
            }
        }
    }

    expect($violations)->toBe([]);
});
