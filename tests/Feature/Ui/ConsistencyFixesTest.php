<?php

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\CaseEvent;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\Deadlines;
use App\Services\Home\ActivityFeed;
use App\Support\Access\AdminNav;
use App\Support\Access\CaseReturnLink;
use App\Support\Access\Nav;
use App\Support\Access\PlatformNav;
use App\Support\Access\WorkspaceTenants;
use App\Support\Format\CaseDueEvents;
use App\Support\Format\Ui;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;

test('workspace and Filament panels render the same header and phone navigation', function (): void {
    $company = Company::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $this->actingAs($admin);
    app(TenantContext::class)->setCompany($company);

    $workspace = $this->get('/employees')->assertOk()->getContent();
    $panel = $this->get('/admin/'.$company->id)->assertOk()->getContent();
    foreach ([$workspace, $panel] as $html) {
        expect($html)->toContain('id="workspace-account"', 'id="workspace-more"', 'id="workspace-settings"');
        expect(substr_count($html, '<header class="sticky top-0 z-50 bg-night text-white">'))->toBe(1);
        expect(substr_count($html, 'id="workspace-account"'))->toBe(1);
    }
    expect(Filament::getPanel('admin')->hasTopbar())->toBeFalse();

    $platformUser = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    Company::factory()->create();
    $html = $this->actingAs($platformUser)->get('/platform')->assertOk()->getContent();
    expect($html)->toContain('id="workspace-account"', 'id="workspace-more"', 'id="workspace-platform"');
    expect(PlatformNav::items($platformUser))->toHaveCount(7);
    expect(WorkspaceTenants::items($platformUser))->toHaveCount(2);
    $platformWorkspace = $this->get('/employees')->assertOk()->getContent();
    expect($platformWorkspace)->toContain('id="workspace-account"', 'id="workspace-more"', 'id="workspace-platform"');
    expect(substr_count($html, '<header class="sticky top-0 z-50 bg-night text-white">'))->toBe(1);
    expect(substr_count($platformWorkspace, '<header class="sticky top-0 z-50 bg-night text-white">'))->toBe(1);
    expect(Filament::getPanel('platform')->hasTopbar())->toBeFalse();
    $theme = File::get(resource_path('css/filament/theme.css'));
    expect($theme)->toContain("@source '../../views/layouts/partials/workspace-navigation.blade.php'", '--color-night:', '--color-signal:');
});

test('employees appear once and management actions retain their policy checks', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $this->actingAs($admin);

    expect(array_column(Nav::items($admin), 'label'))->toContain('Employees');
    expect(array_column(AdminNav::items($admin), 'label'))->not->toContain(__('Employees'));
    $this->get('/employees')->assertOk()->assertSee(__('Add employee'))->assertSee(__('Import CSV'))
        ->assertSee(__('Edit'))->assertSee('/admin/'.$company->id.'/employees/', false);
    $this->get('/admin/'.$company->id.'/employees')->assertOk();
});

test('case return link follows the viewer route and board headers are distinct', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $employee = Employee::factory()->create();
    $case = DisciplinaryCase::factory()->create(['employee_id' => $employee->id,
        'law_version_id' => Law::factory()->create()->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id]);

    $this->actingAs($admin)->get('/cases/'.$case->id)->assertOk()->assertSee(__('Cases board'));
    $this->get('/cases')->assertOk()->assertSee(__('Case number'))->assertSee(__('Status'));
    $committee = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CommitteeMember]);
    $this->actingAs($committee);
    expect(array_column(Nav::items($committee), 'label'))->toContain('My cases');
    expect(CaseReturnLink::for($committee))->toBe('/my-cases');
    $partner = User::factory()->make(['company_id' => null, 'primary_role' => UserRole::HrPartnerStaff]);
    expect(CaseReturnLink::for($partner))->toBeNull();
    $this->get('/cases')->assertForbidden();
});

test('reporters have one reports destination', function (): void {
    $company = Company::factory()->create();
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $this->actingAs($reporter);
    app(TenantContext::class)->setCompany($company);
    $urls = array_column(Nav::items($reporter), 'url');
    expect($urls)->toContain('/reports')->not->toContain('/home');
});

test('activity feed merges only identical consecutive minute entries', function (): void {
    $at = now()->startOfMinute();
    $event = fn (string $type, int $case, int $actor, int $seconds): CaseEvent => new CaseEvent([
        'event_type' => $type, 'case_id' => $case, 'actor_user_id' => $actor,
        'created_at' => $at->copy()->addSeconds($seconds),
    ]);
    $lines = app(ActivityFeed::class)->merge(collect([
        $event('opened', 1, 2, 1), $event('opened', 1, 2, 2),
        $event('changed', 1, 2, 3), $event('opened', 1, 2, 4),
    ]));
    expect($lines->pluck('count')->all())->toBe([2, 1, 1]);
});

test('archived recommendation cases show a closed rail and no appeal clock', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $employee = Employee::factory()->create();
    $case = DisciplinaryCase::factory()->create(['employee_id' => $employee->id,
        'law_version_id' => Law::factory()->create()->id, 'status' => CaseStatus::ArchivedFinal,
        'outcome' => 'recommendation_only', 'defense_letter_issued_at' => now()->subDays(5),
        'closed_at' => now(), 'opened_by_user_id' => $admin->id]);

    $this->actingAs($admin)->get('/cases/'.$case->id)->assertOk()
        ->assertSee(__('Closed'))->assertSee(__('Not applicable'));
    expect(Ui::stage(CaseStatus::ArchivedFinal))->toBe(__('Closed'));
    expect(CaseDueEvents::for($case, app(Deadlines::class))[2]['state'])->toBe('not_applicable');
});

test('Arabic Filament component overrides include select and badge actions', function (): void {
    expect(trans('filament-forms::components.select.actions.clear.label', [], 'ar'))->toBe('مسح الاختيار');
    expect(trans('filament-forms::components.select.actions.remove_option.label', [], 'ar'))->toBe('إزالة :label');
    expect(trans('filament-forms::components.select.search_label', [], 'ar'))->toBe('بحث');
    expect(trans('filament::components/badge.actions.delete.label', [], 'ar'))->toBe('إزالة');
});
