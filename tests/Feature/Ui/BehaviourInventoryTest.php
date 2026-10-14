<?php

use Illuminate\Support\Facades\File;

function uiInventoryMatches(string $pattern, string $source): array
{
    preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
    // Patterns without a capture group return the whole match; one group returns it; more return the groups.
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
        'anchors' => uiInventoryMatches('/\bid\s*=\s*["\'](next-[^"\']+)["\']/s', $source),
        'viewer_controls' => uiInventoryMatches('/(<x-ui\.file-action\b|data-file-list=)/s', $source),
        'controls' => uiInventoryMatches('/\baria-controls="([^"]+)"/s', $source),
    ];
}

test('refreshed screens retain their original behavior surface', function (): void {
    $baseline = json_decode(File::get(base_path('tests/fixtures/ui-inventory.json')), true, flags: JSON_THROW_ON_ERROR);
    // New UI controls may be listed here with a reason once their behavior is reviewed.
    $allowedAdditions = [
        'resources/views/layouts/workspace.blade.php' => [
            'controls' => ['workspace-account-menu'],
        ],
        'resources/views/livewire/cases/show-case.blade.php' => [
            'wire' => [
                ['wire:click', 'linkRelated'],
                ['wire:click', 'markRelatedInError({{ $related['],
                ['wire:model', 'relatedCaseId'],
                ['wire:model', 'relatedReason'],
                ['wire:model', 'relatedErrorReasons.{{ $related['],
                ['wire:model.live.debounce.300ms', 'relatedSearch'],
                ['wire:model', 'mapViolationTypeId'],
                ['wire:click', 'mapViolationType'],
                ['wire:click', 'answerIntakeQuestion({{ $question->id }})'],
                ['wire:model', 'intakeAnswers.{{ $question->id }}'],
                ['wire:key', 'intake-question-{{ $question->id }}'],
            ],
            'errors' => ['relatedCaseId', 'relatedReason', 'mapViolationTypeId'],
            'links' => ['/cases/{{ $other->id }}'],
        ],
        'resources/views/livewire/cases/create-case.blade.php' => [
            'wire' => [['wire:model', 'violationTypeId']],
        ],
        'resources/views/livewire/reports/submit-report.blade.php' => [
            'wire' => [['wire:model', 'violationTypeId']],
        ],
        'resources/views/livewire/reports/inbox.blade.php' => [
            'wire' => [['wire:model', 'mapViolationTypeIds.{{ $report->id }}'],
                ['wire:click', 'mapViolationType({{ $report->id }})']],
        ],
        'resources/views/livewire/cases/partials/ai-editor-controls.blade.php' => [
            'wire' => [
                ['wire:click', 'startSupplementaryComparison'],
                ['wire:click', 'startDocumentComparison'],
                ['wire:click', 'startManualComparison'],
            ],
        ],
    ];

    foreach ($baseline as $path => $expected) {
        $actual = uiInventoryFor(File::get(base_path($path)));
        foreach ($expected as $kind => $items) {
            foreach ($items as $item) {
                expect(in_array($item, $actual[$kind], true))->toBeTrue("Missing {$kind} in {$path}: ".json_encode($item));
            }
            foreach ($actual[$kind] as $item) {
                if (! in_array($item, $items, true)) {
                    expect(in_array($item, $allowedAdditions[$path][$kind] ?? [], true))->toBeTrue("Unreviewed {$kind} in {$path}: ".json_encode($item));
                }
            }
        }
    }
});
