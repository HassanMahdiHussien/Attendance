<?php

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\Signature;
use App\Models\User;
use App\Services\Cases\NextAction;
use App\Support\Format\Ui;
use Carbon\CarbonImmutable;

function homeCase(CaseStatus $status, ?string $issuedAt = null): DisciplinaryCase
{
    $case = new DisciplinaryCase(['status' => $status, 'defense_letter_issued_at' => $issuedAt]);
    $case->id = 17;
    $case->setRelation('documents', collect());
    $case->setRelation('signatures', collect());

    return $case;
}

test('every case status has an explicit next action or is final', function (): void {
    $viewer = User::factory()->make(['primary_role' => UserRole::HrStaff]);
    $service = app(NextAction::class);
    foreach (CaseStatus::cases() as $status) {
        $action = $service->for(homeCase($status), $viewer);
        expect($action === null)->toBe($status === CaseStatus::ArchivedFinal, $status->value);
        if ($action) {
            expect($action->url)->toStartWith('/cases/17');
            expect($action->label)->not->toStartWith('messages.home.');
        }
    }
});

test('a response hand-off and the Baghdad calendar boundary change the response action and badge', function (): void {
    $viewer = User::factory()->make(['primary_role' => UserRole::HrStaff]);
    $service = app(NextAction::class);
    $case = homeCase(CaseStatus::AwaitingEmployeeResponse, '2026-10-01 08:00:00');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 23:59:00', 'Asia/Baghdad'));
    expect($service->for($case, $viewer)->label)->toBe(__('messages.home.record_defense_receipt'));
    $case->setRelation('signatures', collect([new Signature(['purpose' => 'defense_letter_receipt'])]));
    expect($service->for($case, $viewer)->label)->toBe(__('messages.home.waiting_response',
        ['date' => Ui::date($service->for($case, $viewer)->due)]));
    expect($service->all($case, $viewer)->pluck('label'))->toContain(__('messages.home.record_response'));
    expect($service->deadlineAlert($case))->toBe('amber');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 00:00:00', 'Asia/Baghdad'));
    expect($service->for($case, $viewer)->label)->toBe(__('messages.home.record_response_overdue'));
    expect($service->deadlineAlert($case))->toBe('red');
    CarbonImmutable::setTestNow();
});

test('the procedure deadline reaches the red badge 48 hours before the due day ends', function (): void {
    $case = homeCase(CaseStatus::Open, '2026-10-01 08:00:00');
    $service = app(NextAction::class);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 23:58:00', 'Asia/Baghdad'));
    expect($service->deadlineAlert($case))->toBe('amber');
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 23:59:59', 'Asia/Baghdad'));
    expect($service->deadlineAlert($case))->toBe('red');
    CarbonImmutable::setTestNow();
});

test('a recorded decision receipt leaves an open appeal window with an optional appeal action', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'Asia/Baghdad'));
    $case = homeCase(CaseStatus::AwaitingAppealWindow);
    $case->decision_issued_at = '2026-10-01 09:00:00';
    $case->setRelation('signatures', collect([new Signature(['purpose' => 'decision_letter_receipt'])]));
    $service = app(NextAction::class);
    $viewer = User::factory()->make(['primary_role' => UserRole::HrStaff]);

    expect($service->for($case, $viewer)->actor)->toBe('waiting');
    expect($service->all($case, $viewer)->pluck('label'))->toContain(__('messages.home.record_appeal'));
    CarbonImmutable::setTestNow();
});

test('a corrected document ready for confirmation outranks its case stage', function (): void {
    $case = homeCase(CaseStatus::Open);
    $document = new Document(['document_type' => 'recommendations', 'status' => 'ready_for_review',
        'version' => 2, 'is_locked' => false]);
    $document->id = 9;
    $document->setRelation('approvals', collect(array_map(fn (string $role) => new DocumentApproval([
        'role_category' => $role, 'decision' => 'approved',
    ]), ['legal', 'hr', 'labor_union'])));
    $case->setRelation('documents', collect([$document]));
    $viewer = User::factory()->make(['primary_role' => UserRole::HrStaff]);

    expect(app(NextAction::class)->for($case, $viewer)->url)->toBe('/cases/17/documents/9/confirm');
});

test('stage actions use the screen that has the matching control', function (CaseStatus $status, string $actor, string $url): void {
    $viewer = User::factory()->make(['primary_role' => UserRole::HrStaff]);
    $action = app(NextAction::class)->for(homeCase($status), $viewer);
    expect($action->actor)->toBe($actor)->and($action->url)->toBe($url);
})->with([
    [CaseStatus::Draft, 'hr', '/cases/17'],
    [CaseStatus::DefenseLetterIssued, 'waiting', '/cases/17'],
    [CaseStatus::ResponseScannedUploaded, 'hr', '/cases/17'],
    [CaseStatus::AiIngestedResponse, 'hr', '/cases/17/session'],
    [CaseStatus::CommitteeSessionHeld, 'hr', '/cases/17/recommendations'],
    [CaseStatus::RecommendationsAwaitingApprovals, 'waiting', '/cases/17'],
    [CaseStatus::RecommendationsPrinted, 'waiting', '/cases/17'],
    [CaseStatus::AppealPending, 'hr', '/cases/17/appeal'],
    [CaseStatus::DecisionIssued, 'waiting', '/cases/17'],
    [CaseStatus::AppealResolved, 'waiting', '/cases/17'],
]);
