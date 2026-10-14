<?php

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseOpening;

test('HR writes, confirms and issues a manual defense letter', function (): void {
    $company = Company::factory()->create();
    completeCompanySetup($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved', 'effective_from' => '2025-01-01']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create([
        'opened_by_user_id' => $hr->id,
        'violation_place_freetext' => 'Warehouse',
    ]);
    $case = app(CaseOpening::class)->open($case, $hr);

    $page = visitBound('/login')->assertNoJavaScriptErrors()
        ->fill('email', $hr->email)->fill('password', 'password')->press('Log in')
        ->assertPathIs('/home')->assertNoJavaScriptErrors();
    $page->navigate('/cases/'.$case->id)->assertPathIs('/cases/'.$case->id)->assertNoJavaScriptErrors()
        ->click('Write defense request letter')
        ->assertPathIs('/cases/'.$case->id.'/documents/new/edit')
        // wire:navigate swaps the page after the URL changes; type only once the editor has settled.
        ->waitForEvent('networkidle')
        ->assertNoJavaScriptErrors()
        ->fill('body-en', 'Please provide your response to the recorded incident.')
        ->fill('body-ar', 'طلب دفاع يدوي عن الواقعة المسجلة.')
        // Under full-suite load Livewire's late boot after wire:navigate occasionally cleared the first textarea
        // while the second was being typed; re-filling makes the arrange step deterministic.
        ->fill('body-en', 'Please provide your response to the recorded incident.')
        ->assertValue('body-en', 'Please provide your response to the recorded incident.')
        ->assertValue('body-ar', 'طلب دفاع يدوي عن الواقعة المسجلة.')
        ->press('Save draft')
        ->assertSee(__('messages.document.draft_saved'));

    $document = Document::query()->where('case_id', $case->id)->where('document_type', 'defense_letter')->sole();
    $page->press('Submit for review')
        ->assertPathIs('/cases/'.$case->id.'/documents/'.$document->id.'/confirm')
        ->assertNoJavaScriptErrors()
        ->assertSee('Check names and dates')
        ->assertVisible('.font-amiri iframe[title="Document preview"]')
        ->fill('reference', 'HR-2026-027')
        ->press('Confirm and issue')
        ->assertPathIs('/cases/'.$case->id)
        ->assertNoJavaScriptErrors();

    expect($document->fresh()->reference_number)->toBe('HR-2026-027')
        ->and($document->fresh()->is_locked)->toBeTrue()
        ->and($case->fresh()->status)->toBe(CaseStatus::AwaitingEmployeeResponse);
});
