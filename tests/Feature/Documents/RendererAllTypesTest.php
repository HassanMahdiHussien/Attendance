<?php

use App\Documents\DocumentRenderer;
use App\Documents\MissingDocumentFact;
use App\Documents\PdfGenerator;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\User;
use App\Services\Cases\RecommendationsEditor;
use App\Support\Tenancy\TenantContext;

test('recommendations and decision render mandate, two body sections, attendees and side by side signers', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $actor = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $actor->id,
        'defense_letter_issued_at' => now()->subDays(3), 'committee_session_held_at' => now(),
        'defense_acceptance' => 'rejected', 'outcome' => 'warning']);
    $mandate = CommitteeMandate::factory()->create(['order_number' => 'AO-42',
        'effective_from' => now()->subDays(10)]);
    foreach (['legal', 'hr', 'labor_union'] as $role) {
        $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'role_type' => $role,
            'person_name' => strtoupper($role).' Attendee']);
        CommitteeSessionAttendance::factory()->create(['case_id' => $case->id,
            'committee_pool_member_id' => $member->id, 'is_chair' => $role === 'legal']);
    }
    $recommendations = Document::factory()->create(['case_id' => $case->id,
        'document_type' => 'recommendations', 'created_by' => $actor->id]);
    moveCaseToCommitteeStage($case);
    app(RecommendationsEditor::class)->update($recommendations, [
        'ar' => ['body' => 'النتائج', 'secondary_body' => 'التوصيات'],
        'en' => ['body' => 'Findings', 'secondary_body' => 'Recommendations'],
    ], $actor);
    $html = app(DocumentRenderer::class)->render($recommendations, true);
    expect($html)->toContain('AO-42', 'Findings', 'Recommendations', 'LEGAL Attendee', 'اللجنة الانضباطية');
    expect(str_starts_with(app(PdfGenerator::class)->generate($html), '%PDF'))->toBeTrue();
    Employee::factory()->create(['name' => 'General Manager Name', 'is_general_manager' => true]);
    $decision = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'decision_letter',
        'penalty_type' => 'warning', 'created_by' => $actor->id]);
    foreach (['ar', 'en'] as $language) {
        $decision->contents()->create(['company_id' => $company->id, 'language_code' => $language,
            'body' => 'Reasons', 'is_authoritative' => $language === 'ar']);
    }
    $html = app(DocumentRenderer::class)->render($decision, true);
    expect($html)->toContain('General Manager Name', 'LABOR_UNION Attendee', 'WARNING LETTER', 'signatures');
    expect(str_starts_with(app(PdfGenerator::class)->generate($html), '%PDF'))->toBeTrue();
    Employee::query()->where('is_general_manager', true)->first()->update(['is_general_manager' => false]);
    expect(fn () => app(DocumentRenderer::class)->render($decision, true))
        ->toThrow(MissingDocumentFact::class, 'general_manager');
});
