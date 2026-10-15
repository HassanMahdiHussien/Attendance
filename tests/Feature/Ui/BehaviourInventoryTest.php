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
            'permission_conditions' => ['$canManage && in_array(auth(', '$canManageAllIncident',
                '$canManageAllIncident && !$record->defense_letter_issued_at && in_array($record->status, [\App\Enums\CaseStatus::Draft, \App\Enums\CaseStatus::Open], true',
                '$record->incident_id === null || $canManageAllIncident'],
            'role_conditions' => ['$canManage && in_array(auth()->user()->primary_role, [\App\Enums\UserRole::CompanyAdmin, \App\Enums\UserRole::HrStaff], true)'],
            'wire' => [
                ['wire:model', 'incidentEmployeeId'],
                ['wire:model', 'caseDescription'],
                ['wire:model', 'incidentClosureReason'],
                ['wire:click', 'addIncidentEmployee'],
                ['wire:click', 'saveCaseDescription'],
                ['wire:key', 'incident-member-{{ $member->id }}'],
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
            'errors' => ['relatedCaseId', 'relatedReason', 'mapViolationTypeId', 'incidentEmployeeId', 'caseDescription', 'incidentClosureReason'],
            'links' => ['/cases/{{ $other->id }}', '/cases/{{ $member->id }}', '/cases/new?incident_case={{ $record->id }}',
                '/cases/{{ $record->id }}/session?single=1'],
        ],
        'resources/views/livewire/cases/create-case.blade.php' => [
            'wire' => [['wire:model', 'violationTypeId'], ['wire:model', 'employeeIds'],
                ['wire:key', 'case-employee-{{ $employee->id }}']],
            'errors' => ['employeeIds'],
        ],
        'resources/views/livewire/cases/record-session.blade.php' => [
            'wire' => [
                ['wire:key', 'incident-session-case-{{ $incidentCase->id }}'],
                ['wire:model', 'caseMinutes.{{ $incidentCase->id }}'],
                ['wire:model', 'caseOutcomes.{{ $incidentCase->id }}'],
                ['wire:model', 'caseDefenseAcceptance.{{ $incidentCase->id }}'],
                ['wire:model', 'caseVotes.{{ $incidentCase->id }}.{{ $member->id }}'],
            ],
        ],
        'resources/views/livewire/reports/submit-report.blade.php' => [
            'wire' => [['wire:model', 'violationTypeId'], ['wire:model', 'employeeIds'],
                ['wire:key', 'report-employee-{{ $employee->id }}']],
            'errors' => ['employeeIds'],
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
