<?php

use Illuminate\Support\Facades\File;

function uiInventoryMatches(string $pattern, string $source): array
{
    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
    $values = array_map(fn (array $match): string|array => match (count($match)) {
        1 => $match[0], 2 => $match[1], default => array_slice($match, 1)
    }, $matches);
    $values = array_values(array_unique($values, SORT_REGULAR));
    sort($values);

    return $values;
}

function uiInventoryFor(string $source): array
{
    $fileTags = uiInventoryMatches('/<input\b[^>]*\btype\s*=\s*["\']file["\'][^>]*>/s', $source);
    $fileBindings = array_map(fn (string $tag): array => uiInventoryMatches('/\b(wire:[\w.:-]+)\s*=\s*["\']([^"\']*)["\']/s', $tag), $fileTags);
    $fileBindings = array_values(array_unique($fileBindings, SORT_REGULAR));
    sort($fileBindings);

    return [
        'wire' => uiInventoryMatches('/\b(wire:(?:model(?:\.[\w-]+)*|click|submit|key))\s*=\s*["\']([^"\']*)["\']/s', $source),
        'errors' => uiInventoryMatches('/@error\(\s*["\']([^"\']+)["\']\s*\)/s', $source),
        'can' => uiInventoryMatches('/@(?:can|cannot)\(\s*([^)]*)\)/s', $source),
        'role_conditions' => uiInventoryMatches('/@(?:if|elseif)\s*\(([^\n]*?(?:primary_role|->can\(|hasRole)[^\n]*?)\)\s*(?:\n|<|@)/s', $source),
        'permission_calls' => uiInventoryMatches('/(auth\(\)->user\(\)->can\([^)]*\))/s', $source),
        'permission_conditions' => uiInventoryMatches('/@(?:if|elseif)\(([^)]*\$canManage[^)]*)\)/s', $source),
        'file_bindings' => $fileBindings,
        'routes' => uiInventoryMatches('/\broute\(\s*["\']([^"\']+)["\']/s', $source),
        'links' => uiInventoryMatches('/\b(?:href|action|back)\s*=\s*["\'](\/[^"\']*)["\']/s', $source),
        'controls' => uiInventoryMatches('/\baria-controls="([^"]+)"/s', $source),
    ];
}

test('remaining screens match the reviewed interaction inventory', function (): void {
    $baseline = json_decode(File::get(base_path('tests/fixtures/ui-inventory.json')), true, flags: JSON_THROW_ON_ERROR);

    foreach ($baseline as $path => $expected) {
        expect(File::exists(base_path($path)))->toBeTrue("Missing inventoried view: {$path}");
        $source = File::get(base_path($path));
        if ($path === 'resources/views/layouts/workspace.blade.php') {
            $source .= File::get(resource_path('views/layouts/partials/workspace-navigation.blade.php'));
        }
        $actual = uiInventoryFor($source);
        foreach ($expected as $kind => $items) {
            expect($actual[$kind])->toEqualCanonicalizing($items, "Changed {$kind} in {$path}");
        }
    }
});
