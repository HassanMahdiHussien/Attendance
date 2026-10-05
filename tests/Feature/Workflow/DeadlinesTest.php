<?php

use App\Models\CaseEvent;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Services\Cases\Deadlines;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

dataset('calendar anchors', [
    ['2026-06-18', '2026-06-21', '2026-07-03'],
    ['2026-01-29', '2026-02-01', '2026-02-13'],
    ['2026-01-30', '2026-02-02', '2026-02-14'],
    ['2026-01-31', '2026-02-03', '2026-02-15'],
    ['2024-02-26', '2024-02-29', '2024-03-12'],
    ['2024-02-27', '2024-03-01', '2024-03-13'],
    ['2024-02-28', '2024-03-02', '2024-03-14'],
    ['2024-02-29', '2024-03-03', '2024-03-15'],
    ['2025-02-25', '2025-02-28', '2025-03-12'],
    ['2025-02-26', '2025-03-01', '2025-03-13'],
    ['2025-02-27', '2025-03-02', '2025-03-14'],
    ['2025-02-28', '2025-03-03', '2025-03-15'],
    ['2026-04-28', '2026-05-01', '2026-05-13'],
    ['2026-04-29', '2026-05-02', '2026-05-14'],
    ['2026-04-30', '2026-05-03', '2026-05-15'],
    ['2026-08-29', '2026-09-01', '2026-09-13'],
    ['2026-08-30', '2026-09-02', '2026-09-14'],
    ['2026-08-31', '2026-09-03', '2026-09-15'],
    ['2026-12-29', '2027-01-01', '2027-01-13'],
    ['2026-12-30', '2027-01-02', '2027-01-14'],
    ['2026-12-31', '2027-01-03', '2027-01-15'],
]);

test('response and procedure due dates count calendar days', function (string $anchor, string $response, string $procedure): void {
    $case = new DisciplinaryCase(['defense_letter_issued_at' => CarbonImmutable::parse($anchor.' 23:30', 'Asia/Baghdad')]);
    $deadlines = app(Deadlines::class);
    expect($deadlines->responseDue($case)->toDateString())->toBe($response);
    expect($deadlines->procedureDue($case)->toDateString())->toBe($procedure);
})->with('calendar anchors');

test('Baghdad date wins over UTC and appeal ends after thirty calendar days', function (): void {
    $case = new DisciplinaryCase([
        'defense_letter_issued_at' => CarbonImmutable::parse('2026-06-18 23:30', 'Asia/Baghdad'),
        'decision_issued_at' => CarbonImmutable::parse('2026-08-10 23:30', 'Asia/Baghdad'),
    ]);
    $deadlines = app(Deadlines::class);
    expect($deadlines->responseDue($case)->toDateString())->toBe('2026-06-21');
    expect($deadlines->appealEnd($case)->toDateString())->toBe('2026-09-09');
});

test('overdue begins at Baghdad midnight after the due date', function (): void {
    $case = new DisciplinaryCase(['defense_letter_issued_at' => CarbonImmutable::parse('2026-06-18 23:30', 'Asia/Baghdad')]);
    $deadlines = app(Deadlines::class);
    expect($deadlines->procedureStatus($case, CarbonImmutable::parse('2026-07-03 23:59:59', 'Asia/Baghdad')))->toBe('approaching');
    expect($deadlines->procedureStatus($case, CarbonImmutable::parse('2026-07-04 00:00:00', 'Asia/Baghdad')))->toBe('overdue');
});

test('a response recorded by the due date is met and an unrecorded response is missed', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $case = DisciplinaryCase::factory()->create(['defense_letter_issued_at' => '2026-06-18 12:00:00']);
    CaseEvent::create(['company_id' => $company->id, 'case_id' => $case->id,
        'event_type' => 'response_handoff_recorded', 'created_at' => '2026-06-21 23:30:00']);
    expect(app(Deadlines::class)->responseStatus($case, CarbonImmutable::parse('2026-06-22', 'Asia/Baghdad')))->toBe('met');
    $other = DisciplinaryCase::factory()->create(['defense_letter_issued_at' => '2026-06-18 12:00:00']);
    expect(app(Deadlines::class)->responseStatus($other, CarbonImmutable::parse('2026-06-22', 'Asia/Baghdad')))->toBe('missed');
});
