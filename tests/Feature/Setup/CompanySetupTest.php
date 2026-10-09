<?php

use App\Enums\CaseStatus;
use App\Exceptions\TransitionBlocked;
use App\Filament\Admin\Widgets\SetupChecklist;
use App\Models\Company;
use App\Models\CompanyLetterhead;
use App\Models\DisciplinaryCase;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\CaseWorkflow;
use App\Services\Companies\CompanySetup;
use App\Services\Home\MyWork;
use App\Support\Tenancy\TenantContext;

test('setup lists missing items and accepts a complete company', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $setup = app(CompanySetup::class);
    expect(collect($setup->for($company))->where('done', false)->pluck('key')->all())
        ->toContain('letterhead', 'branches', 'committee', 'regulation');
    app()->setLocale('ar');
    expect(collect($setup->for($company))->firstWhere('key', 'committee')['reason'])->toBe(__('messages.setup.mandate_missing'));
    app()->setLocale('en');

    $fixture = completeCompanySetup($company);
    expect($setup->complete($company->fresh()))->toBeTrue();

    $latest = CompanyLetterhead::query()->where('company_id', $company->id)->where('language_code', 'en')->orderByDesc('version')->firstOrFail();
    CompanyLetterhead::create($latest->only(['company_id', 'language_code', 'name', 'footer_text', 'header_mode', 'logo_path']) + [
        'version' => $latest->version + 1, 'needs_review' => true, 'created_at' => now(),
    ]);
    expect($setup->complete($company->fresh()))->toBeFalse();
    expect(collect($setup->for($company))->firstWhere('key', 'letterhead')['reason'])->toContain('EN');
    CompanyLetterhead::create($latest->only(['company_id', 'language_code', 'name', 'footer_text', 'header_mode', 'logo_path']) + [
        'version' => $latest->version + 2, 'needs_review' => false, 'created_at' => now(),
    ]);

    $fixture['mandate']->update(['superseded_at' => now('Asia/Baghdad')->subDay()]);
    expect(collect($setup->for($company))->firstWhere('key', 'committee')['done'])->toBeFalse();
    $fixture['mandate']->update(['superseded_at' => null]);
    $fixture['users']['labor_union']->forceFill(['is_active' => false])->save();
    expect(collect($setup->for($company))->firstWhere('key', 'committee')['reason'])->toContain('labour union');
    $fixture['users']['labor_union']->forceFill(['is_active' => true])->save();
    $company->update(['internal_regulation_answer' => 'yes']);
    expect(collect($setup->for($company->fresh()))->firstWhere('key', 'regulation')['done'])->toBeFalse();
});

test('opening and direct workflow transition require setup', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
    $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $admin->id,
        'law_version_id' => $law->id, 'violation_place_freetext' => 'Gate']);

    expect(fn () => app(CaseOpening::class)->open($case, $admin))->toThrow(TransitionBlocked::class);
    expect(fn () => app(CaseWorkflow::class)->transition($case, CaseStatus::Open, $admin, 'Open'))
        ->toThrow(TransitionBlocked::class);
    expect($case->fresh()->status)->toBe(CaseStatus::Draft);

    completeCompanySetup($company);
    expect(app(CaseOpening::class)->open($case, $admin)->status)->toBe(CaseStatus::Open);
});

test('another company mandate cannot complete setup', function (): void {
    $first = Company::factory()->create();
    app(TenantContext::class)->setCompany($first);
    completeCompanySetup($first);
    $second = Company::factory()->create();
    app(TenantContext::class)->setCompany($second);
    expect(app(CompanySetup::class)->complete($second))->toBeFalse();
    expect(collect(app(CompanySetup::class)->for($second))->firstWhere('key', 'committee')['done'])->toBeFalse();
});

test('home exposes links to admins and a notice state to HR staff', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);

    $adminWork = app(MyWork::class)->for($admin);
    $hrWork = app(MyWork::class)->for($hr);
    expect($adminWork['setup_admin'])->toBeTrue()
        ->and($adminWork['setup'])->not->toBeEmpty()
        ->and($hrWork['setup_admin'])->toBeFalse()
        ->and($hrWork['setup_incomplete'])->toBeTrue()
        ->and($hrWork['setup'])->toBeEmpty();

    actingInAdminTenant($this, $admin, $company);
    expect(SetupChecklist::canView())->toBeTrue();
    actingInAdminTenant($this, $hr, $company);
    expect(SetupChecklist::canView())->toBeFalse();
});

test('a failed regulation import does not satisfy the answer', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    completeCompanySetup($company);
    $company->update(['internal_regulation_answer' => 'yes']);
    $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['company_id' => $company->id, 'kind' => 'internal_regulation',
        'category' => 'regulation', 'approval_status' => 'pending']);
    $run = $law->importRuns()->create(['company_id' => $company->id, 'source_path' => 'regulations/test.txt',
        'source_sha256' => str_repeat('a', 64), 'mode' => 'auto', 'state' => 'failed',
        'started_by' => $user->id, 'started_at' => now()]);
    expect(collect(app(CompanySetup::class)->for($company->fresh()))->firstWhere('key', 'regulation')['done'])->toBeFalse();
    $run->update(['state' => 'queued']);
    expect(collect(app(CompanySetup::class)->for($company->fresh()))->firstWhere('key', 'regulation')['done'])->toBeTrue();
});

test('a company with a prior case still needs a current mandate for a new opening', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $fixture = completeCompanySetup($company);
    $law = Law::factory()->create();
    DisciplinaryCase::factory()->create(['status' => CaseStatus::Open, 'law_version_id' => $law->id]);
    $fixture['mandate']->update(['superseded_at' => now('Asia/Baghdad')->subDay()]);
    expect(app(CompanySetup::class)->complete($company->fresh()))->toBeFalse();
});
