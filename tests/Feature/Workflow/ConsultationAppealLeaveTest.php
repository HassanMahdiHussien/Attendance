<?php

use App\Enums\CaseStatus;
use App\Exceptions\IssuanceBlocked;
use App\Exceptions\TransitionBlocked;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Services\Cases\AppealService;
use App\Services\Cases\ConsultationService;
use App\Services\Cases\TerminationLeaveConfirmation;
use App\Services\Documents\DocumentIssuer;
use App\Support\Access\CaseVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

test('consultation requested early is hidden from the firm until Doc A is signed', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $firm = LawFirmPartner::factory()->create();
    $reviewer = User::factory()->create(['primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $firm->id]);
    DB::table('company_law_firm_partner')->insert(['company_id' => $company->id, 'law_firm_partner_id' => $firm->id]);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['status' => CaseStatus::Open,
        'law_version_id' => $law->id, 'opened_by_user_id' => $hr->id]);
    $service = app(ConsultationService::class);
    $service->request($case, $hr);
    expect($case->fresh()->is_sensitive)->toBeTrue();
    expect(CaseVisibility::for($reviewer)->whereKey($case->id)->exists())->toBeFalse();
    expect(fn () => $service->complete($case, $reviewer, 'Legal note'))->toThrow(TransitionBlocked::class);
    $signed = DisciplinaryCase::factory()->create(['status' => CaseStatus::SignedRecommendationsUploaded,
        'law_version_id' => $law->id, 'opened_by_user_id' => $hr->id]);
    $service->request($signed, $hr);
    expect(CaseVisibility::for($reviewer)->whereKey($signed->id)->exists())->toBeTrue();
    $service->complete($signed, $reviewer, 'Legal note');
    expect($signed->fresh()->legal_review_completed_at)->not->toBeNull();
});

test('termination issuance needs leave confirmation and consultation completion', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['status' => CaseStatus::DecisionLetterReadyForReview,
        'law_version_id' => $law->id, 'opened_by_user_id' => $hr->id,
        'legal_review_requested_at' => now()]);
    $document = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'decision_letter',
        'penalty_type' => 'termination', 'created_by' => $hr->id]);
    expect(fn () => app(DocumentIssuer::class)->issue($document, $hr))->toThrow(IssuanceBlocked::class);
    app(TerminationLeaveConfirmation::class)->confirm($document, $hr);
    expect($document->fresh()->leave_confirmed_by)->toBe($hr->id);
    expect(fn () => app(DocumentIssuer::class)->issue($document, $hr))->toThrow(IssuanceBlocked::class);
});

test('a filed appeal records its outcome and archives the case', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['status' => CaseStatus::AwaitingAppealWindow,
        'law_version_id' => $law->id, 'opened_by_user_id' => $hr->id]);
    DB::table('appeal_trackings')->insert(['company_id' => $company->id, 'case_id' => $case->id,
        'window_start' => '2026-08-10', 'window_end' => '2026-09-09', 'appeal_filed' => false,
        'created_at' => now(), 'updated_at' => now()]);
    $service = app(AppealService::class);
    $service->logFiled($case, $hr, '2026-08-20');
    expect($case->fresh()->status)->toBe(CaseStatus::AppealPending);
    $service->logOutcome($case, $hr, 'Court dismissed the appeal');
    expect($case->fresh()->status)->toBe(CaseStatus::ArchivedFinal);
});
