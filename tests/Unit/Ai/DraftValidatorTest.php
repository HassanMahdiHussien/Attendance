<?php

use App\Ai\DraftValidator;
use App\Ai\OutcomeKeywordFlag;
use App\Enums\DocumentType;

function validAiBodies(): array
{
    return ['languages' => [
        'ar' => ['body' => 'وقعت مخالفة في مكان العمل [[cite:12]].'],
        'en' => ['body' => 'A workplace incident was recorded [[cite:12]].'],
    ], 'notes' => []];
}

test('draft validator rejects foreign markers, missing languages, bare articles and placeholders', function (): void {
    $validator = new DraftValidator;
    $valid = validAiBodies();
    expect($validator->validate($valid, ['ar', 'en'], DocumentType::DefenseLetter, [12], ['candidate_units' => [['id' => 12]]]))->toBe([]);
    $foreign = $valid;
    $foreign['languages']['en']['body'] = 'A workplace incident was recorded [[cite:13]].';
    expect($validator->validate($foreign, ['ar', 'en'], DocumentType::DefenseLetter, [12], []))->not->toBe([]);
    $missing = $valid;
    unset($missing['languages']['en']);
    expect($validator->validate($missing, ['ar', 'en'], DocumentType::DefenseLetter, [12], []))->not->toBe([]);
    $bare = $valid;
    $bare['languages']['ar']['body'] .= ' المادة 43.';
    expect($validator->validate($bare, ['ar', 'en'], DocumentType::DefenseLetter, [12], []))->not->toBe([]);
    $placeholder = $valid;
    $placeholder['languages']['en']['body'] .= ' {employee_name}';
    expect($validator->validate($placeholder, ['ar', 'en'], DocumentType::DefenseLetter, [12], []))->not->toBe([]);
});

test('draft validator rejects invented numbers, unequal citation occurrences and wrong script', function (): void {
    $validator = new DraftValidator;
    $output = validAiBodies();
    $output['languages']['en']['body'] .= ' It happened on 2037.';
    expect($validator->validate($output, ['ar', 'en'], DocumentType::DefenseLetter, [12], ['id' => 12]))->toContain('Invented number in en.');
    $output = validAiBodies();
    $output['languages']['en']['body'] .= ' Another claim [[cite:12]].';
    expect($validator->validate($output, ['ar', 'en'], DocumentType::DefenseLetter, [12], []))->toContain('Citation occurrences differ in en.');
    $output = validAiBodies();
    $output['languages']['en']['body'] = 'هذا نص عربي [[cite:12]].';
    expect($validator->validate($output, ['ar', 'en'], DocumentType::DefenseLetter, [12], []))->toContain('Wrong script in en.');
});

test('outcome keyword detector flags conflicting penalty only', function (): void {
    $flag = new OutcomeKeywordFlag;
    expect($flag->flagged(['ar' => ['body' => 'إنهاء الخدمة'], 'en' => ['body' => 'Dismissal is recommended']], 'warning'))->toBeTrue();
    expect($flag->flagged(['ar' => ['body' => 'إنذار'], 'en' => ['body' => 'A warning']], 'warning'))->toBeFalse();
});
