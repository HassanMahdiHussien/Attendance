<?php

use App\Enums\CaseStatus;
use App\Enums\PoolRole;
use App\Exceptions\ImmutableRecordException;
use App\Exceptions\TransitionBlocked;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseWorkflow;
use App\Services\Cases\DocumentApprovalService;
use App\Services\Cases\RecommendationsEditor;
use App\Support\Access\CaseVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

function approvalFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create([
        'status' => CaseStatus::RecommendationsAwaitingApprovals, 'law_version_id' => $law->id,
        'opened_by_user_id' => $hr->id, 'defense_letter_issued_at' => '2026-06-18 09:00:00',
    ]);
    $mandate = CommitteeMandate::factory()->create(['effective_from' => '2026-06-18']);
    $users = [];
    foreach ([PoolRole::Legal, PoolRole::Hr, PoolRole::LaborUnion] as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
        CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'user_id' => $user->id, 'role_type' => $role]);
        $users[$role->value] = $user;
    }
    $document = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations', 'created_by' => $hr->id]);
    app(RecommendationsEditor::class)->update($document, ['ar' => 'Arabic', 'en' => 'English'], $hr);

    return compact('company', 'hr', 'case', 'document', 'users', 'mandate');
}

test('three distinct pool categories confirm recommendations and snapshot the languages', function (): void {
    $f = approvalFixture();
    $case = $f['case'];
    // The initial HR edit returns the document to citation check.
    app(CaseWorkflow::class)->transition($case, CaseStatus::RecommendationsAwaitingApprovals,
        $f['hr'], 'Citation checked');
    $service = app(DocumentApprovalService::class);
    foreach (['legal', 'hr', 'labor_union'] as $role) {
        $approval = $service->approve($f['document'], $f['users'][$role]);
        expect($approval->languages_present)->toBe(['ar', 'en']);
    }
    expect($case->fresh()->status)->toBe(CaseStatus::RecommendationsConfirmed);
    app(RecommendationsEditor::class)->update($f['document'], ['ar' => 'Changed'], $f['hr']);
    expect($case->fresh()->status)->toBe(CaseStatus::RecommendationsCitationCheck);
    expect($f['document']->approvals()->whereNull('invalidated_at')->count())->toBe(0);
    expect($f['document']->contents()->pluck('language_code')->all())->toBe(['ar']);
});

test('a non-pool approver and an outside-editor content write are refused', function (): void {
    $f = approvalFixture();
    $outsider = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'committee_member']);
    app(CaseWorkflow::class)->transition($f['case'], CaseStatus::RecommendationsAwaitingApprovals,
        $f['hr'], 'Citation checked');
    expect(fn () => app(DocumentApprovalService::class)->approve($f['document'], $outsider))->toThrow(TransitionBlocked::class);
    expect(fn () => $f['document']->contents()->first()->update(['body' => 'Bypass']))->toThrow(ImmutableRecordException::class);
});

test('the first approval in a category wins and rejection needs a comment', function (): void {
    $f = approvalFixture();
    app(CaseWorkflow::class)->transition($f['case'], CaseStatus::RecommendationsAwaitingApprovals,
        $f['hr'], 'Citation checked');
    $service = app(DocumentApprovalService::class);
    $service->approve($f['document'], $f['users']['legal']);
    $second = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'committee_member']);
    CommitteePoolMember::factory()->create(['mandate_id' => $f['mandate']->id, 'user_id' => $second->id,
        'role_type' => PoolRole::Legal]);
    expect(fn () => $service->approve($f['document'], $second))->toThrow(TransitionBlocked::class);
    expect(fn () => $service->reject($f['document'], $f['users']['hr'], ''))->toThrow(TransitionBlocked::class);
    $service->reject($f['document'], $f['users']['hr'], 'Change the reasoning');
    expect($f['case']->fresh()->status)->toBe(CaseStatus::RecommendationsCitationCheck);
});

test('a user linked to two categories must choose and cannot fill both', function (): void {
    $f = approvalFixture();
    app(CaseWorkflow::class)->transition($f['case'], CaseStatus::RecommendationsAwaitingApprovals,
        $f['hr'], 'Citation checked');
    CommitteePoolMember::factory()->create(['mandate_id' => $f['mandate']->id,
        'user_id' => $f['users']['legal']->id, 'role_type' => PoolRole::Hr]);
    $service = app(DocumentApprovalService::class);
    expect(fn () => $service->approve($f['document'], $f['users']['legal']))->toThrow(TransitionBlocked::class);
    $service->approve($f['document'], $f['users']['legal'], PoolRole::Legal);
    expect(fn () => $service->approve($f['document'], $f['users']['legal'], PoolRole::Hr))->toThrow(TransitionBlocked::class);
});

test('an hr staff account in the HR pool can see and approve a case it did not open', function (): void {
    $f = approvalFixture();
    app(CaseWorkflow::class)->transition($f['case'], CaseStatus::RecommendationsAwaitingApprovals,
        $f['hr'], 'Citation checked');
    $hrStaff = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'hr_staff']);
    CommitteePoolMember::factory()->create(['mandate_id' => $f['mandate']->id, 'user_id' => $hrStaff->id, 'role_type' => PoolRole::Hr]);

    expect(CaseVisibility::for($hrStaff)->whereKey($f['case']->id)->exists())->toBeTrue();
    $approval = app(DocumentApprovalService::class)->approve($f['document'], $hrStaff, PoolRole::Hr);
    expect($approval->role_category)->toBe(PoolRole::Hr);

    // Visibility is limited to the approval stage; it is not a standing grant.
    app(CaseWorkflow::class)->transition($f['case']->fresh(), CaseStatus::RecommendationsCitationCheck,
        $f['hr'], 'Rewrite');
    expect(CaseVisibility::for($hrStaff)->whereKey($f['case']->id)->exists())->toBeFalse();
});

test('reporters and department managers cannot be linked to the committee pool', function (string $role): void {
    $f = approvalFixture();
    $user = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => $role]);

    expect(fn () => CommitteePoolMember::factory()->create([
        'mandate_id' => $f['mandate']->id, 'user_id' => $user->id, 'role_type' => PoolRole::Hr,
    ]))->toThrow(ValidationException::class);
})->with(['reporter', 'dept_manager']);
