<?php

use App\Enums\CaseStatus;
use App\Exceptions\IssuanceBlocked;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseDocumentIssuance;
use App\Services\Documents\DocumentCorrector;
use App\Support\Tenancy\TenantContext;

test('first defense issuance moves the case and a correction preserves the clock and stage', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::DefenseLetterConfirmed, 'opened_by_user_id' => $actor->id]);
    $draft = Document::factory()->create(['case_id' => $case->id, 'created_by' => $actor->id]);
    foreach (['ar', 'en'] as $language) {
        $draft->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'Text', 'is_authoritative' => $language === 'ar']);
    }
    $issuance = app(CaseDocumentIssuance::class);
    $issued = $issuance->issue($draft, $actor);
    expect($case->fresh()->status)->toBe(CaseStatus::AwaitingEmployeeResponse);
    $anchor = $case->fresh()->defense_letter_issued_at;
    expect($anchor)->not->toBeNull();

    $correction = app(DocumentCorrector::class)->startCorrection($issued, 'Corrected spelling', $actor);
    $issuance->issue($correction, $actor);
    expect($case->fresh()->status)->toBe(CaseStatus::AwaitingEmployeeResponse);
    expect($case->fresh()->defense_letter_issued_at->equalTo($anchor))->toBeTrue();
    expect($case->events()->where('event_type', 'document_corrected_issued')->count())->toBe(1);
});

test('a first issue outside its stage is refused', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $actor->id]);
    $draft = Document::factory()->create(['case_id' => $case->id, 'created_by' => $actor->id]);
    expect(fn () => app(CaseDocumentIssuance::class)->issue($draft, $actor))->toThrow(IssuanceBlocked::class);
});
