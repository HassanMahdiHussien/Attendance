<?php

use App\Enums\CaseStatus;
use App\Enums\DocumentType;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\RecommendationsEditor;
use App\Support\Tenancy\TenantContext;

test('legal, HR and union pool members approve recommendations in separate sessions', function (): void {
    $company = Company::factory()->create();
    $setup = completeCompanySetup($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $case = DisciplinaryCase::factory()->create([
        'company_id' => $company->id,
        'branch_id' => $setup['branch']->id,
        'law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::RecommendationsAwaitingApprovals,
        'defense_letter_issued_at' => now(),
        'opened_by_user_id' => $hr->id,
    ]);
    $document = Document::factory()->create([
        'company_id' => $company->id,
        'case_id' => $case->id,
        'document_type' => DocumentType::Recommendations,
        'created_by' => $hr->id,
    ]);
    $editor = app(RecommendationsEditor::class);
    $editor->update($document, [
        'ar' => ['body' => 'Recorded findings in ar', 'secondary_body' => 'Human recommendations in ar'],
        'en' => ['body' => 'Recorded findings in en', 'secondary_body' => 'Human recommendations in en'],
    ], $hr);
    $editor->submitForApproval($document, $hr);

    foreach (['legal', 'hr', 'labor_union'] as $category) {
        /** @var User $member */
        $member = $setup['users'][$category];
        // A fresh login page per member: typing into the page reached through the logout redirect raced Livewire's boot.
        $page = visitBound('/login')->waitForEvent('networkidle')->assertNoJavaScriptErrors();
        $page->fill('email', $member->email)->fill('password', 'password')->press('Log in')
            ->assertPathIs('/home')->assertNoJavaScriptErrors();
        $page->navigate('/committee')->assertPathIs('/committee')->assertNoJavaScriptErrors()
            ->click('a[href="/committee/approvals/'.$document->id.'"]')
            ->assertPathIs('/committee/approvals/'.$document->id)
            ->assertNoJavaScriptErrors()
            ->assertSee('Recorded findings in en')
            ->assertSee('Human recommendations in ar')
            ->press('Approve')
            ->assertPathIs('/committee')
            ->assertNoJavaScriptErrors();
        // Browser requests reset the tenant context the in-process server shares with the test.
        app(TenantContext::class)->setCompany($company);
        expect($document->approvals()->where('role_category', $category)->where('decision', 'approved')->exists())->toBeTrue();
        $page->click('aside form[action$="/logout"] button')
            ->assertPathIs('/login')->assertNoJavaScriptErrors();
    }

    app(TenantContext::class)->setCompany($company);
    expect($case->fresh()->status)->toBe(CaseStatus::RecommendationsConfirmed)
        ->and($document->approvals()->where('decision', 'approved')->count())->toBe(3);
});
