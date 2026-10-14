<?php

use App\Enums\ImportMode;
use App\Enums\Jurisdiction;
use App\Enums\LegalUnitStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Pages\InternalRegulation;
use App\Filament\Platform\Pages\MismatchQueue;
use App\Filament\Platform\Resources\LawResource;
use App\Filament\Platform\Resources\LawResource\Pages\EditLaw;
use App\Jobs\RenderLegalPages;
use App\LegalLibrary\LawImportService;
use App\LegalLibrary\MismatchResolver;
use App\Models\Company;
use App\Models\Law;
use App\Models\LegalImportPage;
use App\Models\LegalImportRun;
use App\Models\LegalUnit;
use App\Models\LegalUnitApproval;
use App\Models\User;
use App\Support\Format\LegalLibraryLabels;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('range requirement, cancellation and corrected re-import preserve the cancelled pages', function (): void {
    Bus::fake([RenderLegalPages::class]);
    $actor = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($actor);
    app(TenantContext::class)->setAllCompanies();
    $law = Law::factory()->create(['requires_page_range' => true]);
    $source = base_path('tests/fixtures/legal/text-law.pdf');
    expect(fn () => app(LawImportService::class)->start($law, $actor, $source, null, null, ImportMode::Auto))
        ->toThrow(ValidationException::class);
    $first = app(LawImportService::class)->start($law, $actor, $source, 1, 1, ImportMode::Auto);
    $page = LegalImportPage::create(['run_id' => $first->id, 'page_number' => 1,
        'image_path' => 'legal-library/runs/'.$first->id.'/pages/1.png',
        'image_sha256' => str_repeat('a', 64)]);
    app(LawImportService::class)->cancel($first, $actor, 'Wrong gazette boundary');
    expect($first->fresh()->state)->toBe('cancelled');
    expect($page->fresh())->not->toBeNull();
    $second = app(LawImportService::class)->start($law, $actor, $source, 1, 2, ImportMode::Auto);
    expect($second->state)->toBe('queued');
});

test('page image route serves private pages only to visible users', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $actor = User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::CompanyAdmin]);
    app(TenantContext::class)->runForCompany($a, function () use ($a, $actor): void {
        $law = Law::factory()->create(['company_id' => $a->id, 'kind' => 'internal_regulation']);
        $run = LegalImportRun::create(['law_id' => $law->id, 'company_id' => $a->id,
            'source_path' => 'fixture.pdf', 'source_sha256' => str_repeat('a', 64), 'mode' => 'auto',
            'state' => 'completed', 'started_by' => $actor->id, 'started_at' => now()]);
        $path = "legal-library/runs/{$run->id}/pages/1.png";
        Storage::disk('local')->put($path, 'png bytes');
        LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => $path, 'image_sha256' => hash('sha256', 'png bytes')]);
        $this->runId = $run->id;
    });
    $url = route('legal-library.pages.show', ['run' => $this->runId, 'page' => 1]);
    $this->actingAs($actor)->get($url)->assertOk()->assertHeader('content-type', 'image/png');
    $this->get(route('legal-library.pages.show', ['run' => $this->runId, 'page' => 2]))->assertNotFound();
    $outsider = User::factory()->create(['company_id' => $b->id, 'primary_role' => UserRole::CompanyAdmin]);
    $this->actingAs($outsider)->get($url)->assertNotFound();
    $platform = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($platform)->get($url)->assertOk();
});

test('statute page images are visible to internal users except reporters', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    app(TenantContext::class)->runAcrossAllCompanies(function () use ($actor): void {
        $law = Law::factory()->create();
        $run = LegalImportRun::create(['law_id' => $law->id, 'source_path' => 'fixture.pdf',
            'source_sha256' => str_repeat('a', 64), 'mode' => 'auto', 'state' => 'completed',
            'started_by' => $actor->id, 'started_at' => now()]);
        $path = "legal-library/runs/{$run->id}/pages/1.png";
        Storage::disk('local')->put($path, 'png bytes');
        LegalImportPage::create(['run_id' => $run->id, 'page_number' => 1,
            'image_path' => $path, 'image_sha256' => hash('sha256', 'png bytes')]);
        $this->runId = $run->id;
    });
    $url = route('legal-library.pages.show', ['run' => $this->runId, 'page' => 1]);
    foreach ([UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::CommitteeMember] as $role) {
        $this->actingAs(User::factory()->create(['company_id' => $company->id, 'primary_role' => $role]))
            ->get($url)->assertOk();
    }
    $this->actingAs(User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]))
        ->get($url)->assertNotFound();
});

test('import action requires confirmation for an explicit page range', function (): void {
    $actor = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    app(TenantContext::class)->setAllCompanies();
    $law = Law::factory()->create(['source_file_path' => 'tests/fixtures/legal/text-law.pdf']);
    Livewire::test(EditLaw::class, ['record' => $law->id])->callAction('import', [
        'page_from' => 1, 'page_to' => 2, 'mode' => 'auto', 'range_confirmed' => false,
    ])->assertHasErrors(['range_confirmed']);
    expect($law->importRuns()->count())->toBe(0);
});

test('platform library and mismatch queue reject company roles', function (): void {
    $company = Company::factory()->create();
    $platform = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($platform);
    $this->get(LawResource::getUrl('index', panel: 'platform'))->assertOk();
    $this->get('/platform/mismatch-queue')->assertOk();
    foreach ([UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::Reporter, UserRole::CommitteeMember] as $role) {
        $this->actingAs(User::factory()->create(['company_id' => $company->id, 'primary_role' => $role]));
        expect($this->get(LawResource::getUrl('index', panel: 'platform'))->status())->toBeIn([302, 403, 404]);
        expect($this->get('/platform/mismatch-queue')->status())->toBeIn([302, 403, 404]);
    }
});

test('admin regulation page admits company admins and permitted reviewers only', function (): void {
    $company = Company::factory()->create();
    Permission::findOrCreate('review_internal_regulation', 'web');
    foreach ([UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::Reporter, UserRole::CommitteeMember] as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => $role]);
        if ($role === UserRole::HrStaff) {
            $user->givePermissionTo('review_internal_regulation');
        }
        $this->actingAs($user);
        $response = $this->get('/admin/'.$company->id.'/internal-regulation');
        if (in_array($role, [UserRole::CompanyAdmin, UserRole::HrStaff], true)) {
            $response->assertOk();
        } else {
            expect($response->status())->toBeIn([302, 403, 404]);
        }
    }
});

test('mismatch actions enforce review policy on Livewire calls', function (): void {
    $company = Company::factory()->create();
    $actor = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($actor);
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    app(TenantContext::class)->setAllCompanies();
    $law = Law::factory()->create();
    $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'status' => 'mismatch',
        'extraction_a' => 'نص أ', 'extraction_b' => 'نص ب']);
    Livewire::test(MismatchQueue::class)->call('selectUnit', $unit->id)
        ->call('resolveUnit', 'a')->assertHasNoErrors();
    expect($unit->fresh()->status->value)->toBe('verified_human');
    $other = LegalUnit::factory()->create(['law_id' => $law->id, 'status' => 'mismatch', 'article_number' => '9',
        'extraction_a' => 'نص أ', 'extraction_b' => 'نص ب']);
    $wrong = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    // A company user can neither open the statute queue nor resolve a statute unit through the service.
    expect(MismatchQueue::canAccess())->toBeTrue();
    $this->actingAs($wrong);
    expect(MismatchQueue::canAccess())->toBeFalse();
    $this->get('/platform/mismatch-queue')->assertForbidden();
    expect(fn () => app(MismatchResolver::class)->resolve($other, $wrong, 'a', null))
        ->toThrow(HttpException::class);
    expect($other->fresh()->status->value)->toBe('mismatch');
});

test('regulation approval is reviewer-only and tenant-scoped', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::CompanyAdmin]);
    $reviewer = User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::HrStaff]);
    Permission::findOrCreate('review_internal_regulation', 'web');
    $reviewer->givePermissionTo('review_internal_regulation');
    actingInAdminTenant($this, $admin, $a);
    $law = Law::factory()->create(['company_id' => $a->id, 'kind' => 'internal_regulation']);
    $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'status' => 'verified_text']);
    $mismatch = LegalUnit::factory()->create(['law_id' => $law->id, 'status' => 'mismatch',
        'article_number' => '2', 'extraction_a' => 'النص الأول', 'extraction_b' => 'النص الثاني']);
    Livewire::test(InternalRegulation::class)->call('selectLaw', $law->id)
        ->call('approveUnit', $unit->id)->assertForbidden();
    Livewire::test(InternalRegulation::class)->call('selectUnit', $mismatch->id)->assertForbidden();
    actingInAdminTenant($this, $reviewer, $a);
    Livewire::test(InternalRegulation::class)->call('upload')->assertForbidden();
    Livewire::test(InternalRegulation::class)->call('selectLaw', $law->id)
        ->call('approveUnit', $unit->id)->assertHasNoErrors();
    Livewire::test(InternalRegulation::class)->call('selectUnit', $mismatch->id)
        ->call('resolveUnit', 'b')->assertHasNoErrors();
    expect($mismatch->fresh()->status->value)->toBe('verified_human');
    actingInAdminTenant($this, $reviewer, $b);
    Livewire::test(InternalRegulation::class)->assertForbidden();
});

test('legal library labels cover every displayed code', function (): void {
    foreach (['labor', 'social_security', 'regulation', 'other'] as $value) {
        expect(LegalLibraryLabels::category($value))->not->toBe($value);
    }
    foreach (Jurisdiction::cases() as $value) {
        expect(LegalLibraryLabels::jurisdiction($value->value))->not->toBe($value->value);
    }
    foreach (LegalUnitStatus::cases() as $value) {
        expect(LegalLibraryLabels::unitStatus($value->value))->not->toBe($value->value);
    }
    foreach (['queued', 'reading', 'ai_pending', 'splitting', 'completed', 'failed', 'cancelled'] as $value) {
        expect(LegalLibraryLabels::runState($value))->not->toBe($value);
    }
    foreach (['pending', 'done', 'failed', 'unavailable'] as $value) {
        expect(LegalLibraryLabels::pageStatus($value))->not->toBe($value);
    }
    foreach (['text', 'ocr'] as $value) {
        expect(LegalLibraryLabels::textMethod($value))->not->toBe($value);
    }
    foreach (['text_differs', 'structure_differs', 'one_reading_only', 'duplicate_heading', 'page_failed', 'illegible'] as $value) {
        expect(LegalLibraryLabels::mismatchReason($value))->not->toBe($value);
    }
});

test('regulation shows a localized summary and approved units without approval controls', function (): void {
    $company = Company::factory()->create();
    $reviewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    Permission::findOrCreate('review_internal_regulation', 'web');
    $reviewer->givePermissionTo('review_internal_regulation');
    actingInAdminTenant($this, $reviewer, $company);
    $law = Law::factory()->create(['company_id' => $company->id, 'kind' => 'internal_regulation']);
    LegalImportRun::create(['law_id' => $law->id, 'company_id' => $company->id,
        'source_path' => 'fixture.txt', 'source_sha256' => str_repeat('a', 64), 'mode' => 'text',
        'state' => 'completed', 'started_by' => $reviewer->id, 'started_at' => now(),
        'counts' => ['pages' => 0, 'units' => 1, 'verified_text' => 1]]);
    $unit = LegalUnit::factory()->create(['law_id' => $law->id, 'status' => 'verified_text']);
    LegalUnitApproval::create(['legal_unit_id' => $unit->id, 'company_id' => $company->id,
        'approved_by' => $reviewer->id, 'created_at' => now()]);
    Livewire::test(InternalRegulation::class)->call('selectLaw', $law->id)
        ->assertSee('1 Units — 1 Verified from source text')
        ->assertSee('Approved')
        ->assertSee($reviewer->name)
        ->assertDontSee('verified_text')
        ->assertDontSee('{"pages"')
        ->assertDontSee('Approve all verified units')
        ->assertDontSee('wire:click="approveUnit');
});

test('page range preview uses compact JPEGs', function (): void {
    $actor = User::factory()->create(['primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($actor);
    app(TenantContext::class)->setAllCompanies();
    $law = Law::factory()->create();
    $paths = app(LawImportService::class)->previewPages($law, base_path('tests/fixtures/legal/text-law.pdf'), 1, 1);
    $path = Storage::disk('local')->path($paths[1]);
    expect(file_get_contents($path, false, null, 0, 3))->toBe("\xFF\xD8\xFF");
    expect(filesize($path))->toBeLessThan(200 * 1024);
    expect(max(getimagesize($path)[0], getimagesize($path)[1]))->toBeLessThanOrEqual(900);
});

test('workspace navigation exposes regulation to admins and to every permitted reviewer', function (): void {
    $company = Company::factory()->create();
    Permission::findOrCreate('review_internal_regulation', 'web');
    foreach ([UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::Reporter, UserRole::CommitteeMember] as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => $role]);
        if ($role === UserRole::HrStaff) {
            $unpermitted = $this->actingAs($user)->get('/cases');
            $unpermitted->assertDontSee('/admin/'.$company->id.'/internal-regulation');
            $user->givePermissionTo('review_internal_regulation');
        }
        if (in_array($role, [UserRole::Reporter, UserRole::CommitteeMember], true)) {
            $user->givePermissionTo('review_internal_regulation');
        }
        $path = match ($role) {
            UserRole::Reporter => '/reports', UserRole::CommitteeMember => '/my-cases', default => '/cases',
        };
        // Company admins always, and any company user holding the review permission (the company's lawyer).
        $this->actingAs($user)->get($path)->assertOk()->assertSee('/admin/'.$company->id.'/internal-regulation');
    }
});
