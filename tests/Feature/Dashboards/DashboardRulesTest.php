<?php

use App\Enums\CaseStatus;
use App\Filament\Admin\Pages\CompanySettings;
use App\Filament\Platform\Widgets\CompaniesOverview;
use App\Livewire\Dashboard\CaseDrilldown;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\HrPartner;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('incident grouping normalises violation types and preserves the exact drill-down count', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Baghdad'));
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $first = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'violation_type' => 'تأخير', 'violation_time' => '2026-09-20 12:00:00']);
    foreach ([' تأخير  ', 'تأخير'] as $type) {
        DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
            'opened_by_user_id' => $admin->id, 'employee_id' => $first->employee_id, 'branch_id' => $first->branch_id,
            'violation_type' => $type, 'violation_time' => '2026-09-21 12:00:00']);
    }
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'employee_id' => $first->employee_id, 'branch_id' => $first->branch_id,
        'violation_type' => 'تأخير', 'violation_time' => '2026-02-01 12:00:00']);
    $filters = DashboardFilters::fromInput($admin, ['period' => '6']);
    $metrics = app(CaseMetrics::class);
    $type = collect($metrics->hotspots($admin, $filters)['violation'])->firstWhere('key', 'تأخير');
    expect($type['count'])->toBe(3)->and($type['previous'])->toBe(1)->and($type['delta'])->toBe(2)
        ->and($metrics->hotspotQuery($admin, $filters, 'violation', 'تأخير')->count())->toBe(3);
    $department = collect($metrics->thresholds($admin, $filters, 3, 90))->firstWhere('group', 'department');
    expect($department['count'])->toBe(3)
        ->and($metrics->thresholdQuery($admin, $filters, 'department', $department['key'], 'تأخير', 90)->count())->toBe(3);
    expect(collect($metrics->hotspots($admin, $filters)['location'])->firstWhere('key', '')['label'])->toBe('Location unspecified')
        ->and(collect($metrics->thresholds($admin, $filters, 3, 90))->where('group', 'location')->count())->toBe(0);
    $company->update(['hotspot_min_cases' => 4, 'hotspot_window_days' => 30]);
    expect($metrics->thresholds($admin, $filters, $company->hotspot_min_cases, $company->hotspot_window_days))->toBe([]);
});

test('deadline boundary alerts follow Baghdad due-day classification', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 23:59:59.999999', 'Asia/Baghdad'));
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'defense_letter_issued_at' => '2026-09-24 08:00:00']);
    $metrics = app(CaseMetrics::class);
    $types = fn () => collect($metrics->alerts($admin, DashboardFilters::fromInput($admin, ['period' => '6'])))->pluck('type');
    expect($types())->toContain('procedure_approaching');
    $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00', 'Asia/Baghdad'));
    expect($types())->toContain('procedure_approaching');
    $this->travelTo(CarbonImmutable::parse('2026-10-10 00:00', 'Asia/Baghdad'));
    expect($types())->toContain('procedure_overdue');
    expect(collect($metrics->alerts($admin, DashboardFilters::fromInput($admin, ['period' => '6'])))
        ->firstWhere('type', 'procedure_overdue')['remaining_hours'])->toBeLessThan(0);
});

test('dashboard sections reject other roles and Livewire filter updates remain authorised', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $this->actingAs($reporter)->get('/dashboard')->assertForbidden();
    $this->get('/dashboard/cases?metric=open')->assertForbidden();
    Livewire::actingAs($reporter)->test(HrDashboard::class)->assertStatus(403);
    Livewire::actingAs($reporter)->test(CaseDrilldown::class)->assertStatus(403);
    $this->actingAs($admin)->get('/dashboard/cases?metric=unknown')->assertNotFound();
    $this->get('/dashboard/cases?metric=hotspot&group=bad&key=1')->assertNotFound();
    $this->get('/dashboard/cases?metric=open&period=13')->assertNotFound();
    Livewire::actingAs($admin)->test(HrDashboard::class)->set('period', '3')->assertSee('Open cases');
    $otherCompany = Company::factory()->create();
    $foreignBranch = app(TenantContext::class)->runForCompany($otherCompany, fn () => Branch::factory()->create());
    app(TenantContext::class)->setCompany($company);
    Livewire::actingAs($admin)->test(HrDashboard::class)->set('branch', (string) $foreignBranch->id)->assertHasErrors(['branch']);
});

test('empty dashboard figures have a zero denominator and no percentage', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $counts = app(CaseMetrics::class)->counts($admin, DashboardFilters::fromInput($admin, ['period' => '6']));
    expect($counts['open'])->toBe(0)->and($counts['within15_denominator'])->toBe(0)
        ->and($counts['within15_percent'])->toBeNull();
    $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('No cases in this period.');
});

test('a recorded response clears its alert and appeal alerts require the appeal window state', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-08 12:00', 'Asia/Baghdad'));
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $responded = DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::ResponseReceived, 'opened_by_user_id' => $admin->id,
        'defense_letter_issued_at' => '2026-09-30 09:00:00']);
    $responded->events()->create(['company_id' => $company->id, 'event_type' => 'response_handoff_recorded',
        'created_at' => '2026-10-06 09:00:00']);
    $appeal = DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::AwaitingAppealWindow, 'opened_by_user_id' => $admin->id,
        'decision_issued_at' => '2026-09-09 09:00:00']);
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::DecisionIssued, 'opened_by_user_id' => $admin->id,
        'decision_issued_at' => '2026-09-09 09:00:00']);
    $alerts = app(CaseMetrics::class)->alerts($admin, DashboardFilters::fromInput($admin, ['period' => '6']));
    expect(collect($alerts)->where('case.id', $responded->id)->pluck('type'))->not->toContain('response_missed')
        ->and(collect($alerts)->where('type', 'appeal_approaching')->pluck('case.id')->all())->toBe([$appeal->id]);
});

test('the fifteen-day percentage uses completed procedures with a defense date', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Baghdad'));
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    foreach (['2026-09-16 15:00:00', '2026-09-18 15:00:00'] as $date) {
        DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
            'status' => CaseStatus::ArchivedFinal, 'opened_by_user_id' => $admin->id, 'outcome' => 'warning',
            'defense_letter_issued_at' => '2026-09-01 08:00:00', 'decision_issued_at' => $date,
            'closed_at' => '2026-10-01 09:00:00']);
    }
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::ArchivedFinal, 'opened_by_user_id' => $admin->id, 'outcome' => 'warning',
        'decision_issued_at' => '2026-09-20 09:00:00', 'closed_at' => '2026-10-01 09:00:00']);
    $filters = DashboardFilters::fromInput($admin, ['period' => '6']);
    $metrics = app(CaseMetrics::class);
    $counts = $metrics->counts($admin, $filters);
    expect($counts['within15_numerator'])->toBe(1)
        ->and($counts['within15_denominator'])->toBe(2)
        ->and($counts['within15_percent'])->toBe(50.0)
        ->and($counts['warning'])->toBe(3)
        ->and($metrics->metricQuery($admin, $filters, 'within15_numerator')->count())->toBe(1);
});

test('recommendation-only closure is the completion date for legal timing', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'Asia/Baghdad'));
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::ArchivedFinal, 'opened_by_user_id' => $admin->id,
        'outcome' => 'recommendation_only', 'defense_letter_issued_at' => '2026-09-01 08:00:00',
        'closed_at' => '2026-09-16 21:00:00']);
    $counts = app(CaseMetrics::class)->counts($admin, DashboardFilters::fromInput($admin, ['period' => '6']));
    expect($counts['recommendation_only'])->toBe(1)
        ->and($counts['within15_numerator'])->toBe(1)
        ->and($counts['within15_denominator'])->toBe(1)
        ->and($counts['average_days'])->toBe(15.0);
});

test('reviewer aggregates include archived consultations while hidden oldest cases have no link', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $law = Law::factory()->create();
    $firm = LawFirmPartner::factory()->create();
    DB::table('company_law_firm_partner')->insert(['company_id' => $company->id, 'law_firm_partner_id' => $firm->id]);
    $reviewer = User::factory()->create(['company_id' => null, 'primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $firm->id]);
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::ArchivedFinal, 'legal_review_requested_at' => now()->subMonth(),
        'legal_review_completed_at' => now()->subDays(2)]);
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id,
        'status' => CaseStatus::Open, 'legal_review_requested_at' => now()->subDays(4),
        'defense_letter_issued_at' => now()->subDays(6)]);
    $summary = app(CaseMetrics::class)->reviewerSummary($reviewer);
    expect($summary['waiting'])->toBe(1)->and($summary['completed_month'])->toBe(1)
        ->and($summary['oldest']['url'])->toBeNull();
    $this->actingAs($reviewer)->get('/my-cases')->assertOk()->assertSee('Consultations completed this month');
});

test('HR staff add-on changes every dashboard count and its list together', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $other = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $law = Law::factory()->create();
    $own = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $hr->id, 'assigned_hr_user_id' => $hr->id]);
    $foreign = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $other->id, 'assigned_hr_user_id' => $other->id]);
    $filters = DashboardFilters::fromInput($hr, ['period' => '6']);
    $metrics = app(CaseMetrics::class);
    expect($metrics->counts($hr, $filters)['open'])->toBe(1)
        ->and($metrics->metricQuery($hr, $filters, 'open')->pluck('cases.id')->all())->toBe([$own->id]);
    $this->actingAs($hr)->get('/dashboard/cases?metric=open')->assertOk()->assertDontSee($foreign->employee->name);
    $hr->givePermissionTo('view_all_company_cases');
    expect($metrics->counts($hr, $filters)['open'])->toBe(2)
        ->and($metrics->metricQuery($hr, $filters, 'open')->count())->toBe(2);
    $this->get('/dashboard/cases?metric=open')->assertOk()->assertSee($foreign->employee->name);
});

test('company admins can change validated hotspot settings with an audit entry', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    actingInAdminTenant($this, $admin, $company);
    Livewire::test(CompanySettings::class)->fillForm(['hotspot_min_cases' => 4, 'hotspot_window_days' => 30])
        ->call('save')->assertHasNoFormErrors();
    expect($company->fresh()->hotspot_min_cases)->toBe(4)
        ->and($company->fresh()->hotspot_window_days)->toBe(30)
        ->and(AuditLog::query()->where('action', 'company.updated')->where('entity_id', $company->id)->exists())->toBeTrue();
    actingInAdminTenant($this, $admin, $company);
    Livewire::test(CompanySettings::class)->fillForm(['hotspot_min_cases' => 1, 'hotspot_window_days' => 366])
        ->call('save')->assertHasFormErrors(['hotspot_min_cases', 'hotspot_window_days']);
});

test('platform overview groups company figures and last activity', function (): void {
    $company = Company::factory()->create(['name' => 'شركة ألف']);
    $other = Company::factory()->create(['name' => 'شركة باء']);
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
    $law = Law::factory()->create();
    $case = app(TenantContext::class)->runForCompany($company, fn () => DisciplinaryCase::factory()->create([
        'law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'defense_letter_issued_at' => now()->subDays(20), 'legal_review_requested_at' => now()->subDay(),
    ]));
    DB::table('case_events')->insert(['company_id' => $company->id, 'case_id' => $case->id,
        'event_type' => 'test_activity', 'created_at' => now()]);
    $this->actingAs($platform);
    Filament::setCurrentPanel(Filament::getPanel('platform'));
    app(TenantContext::class)->setAllCompanies();
    $rows = collect(app(CompaniesOverview::class)->companies());
    expect($rows)->toHaveCount(2);
    $first = $rows->firstWhere('name', 'شركة ألف');
    expect($first['open'])->toBe(1)->and($first['past_due'])->toBe(1)
        ->and($first['consultations'])->toBe(1)->and($first['last_activity'])->not->toBeNull()
        ->and($first['url'])->toContain((string) $company->id);
    expect($rows->firstWhere('name', 'شركة باء')['open'])->toBe(0);
});

test('only company administrators and HR staff reach the dashboard and count drill-down', function (string $role, int $status): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    // Platform-level roles have no company and need their firm / partner link.
    $viewer = User::factory()->create(match ($role) {
        'platform_admin' => ['company_id' => null, 'primary_role' => $role],
        'external_legal_reviewer' => ['company_id' => null, 'primary_role' => $role,
            'law_firm_partner_id' => LawFirmPartner::factory()->create()->id],
        'hr_partner_staff' => ['company_id' => null, 'primary_role' => $role,
            'hr_partner_id' => HrPartner::factory()->create()->id],
        default => ['company_id' => $company->id, 'primary_role' => $role],
    });
    $this->actingAs($viewer)->get('/dashboard')->assertStatus($status);
    $this->get('/dashboard/cases?metric=open')->assertStatus($status);
})->with([
    ['company_admin', 200], ['hr_staff', 200], ['platform_admin', 403],
    ['external_legal_reviewer', 403], ['hr_partner_staff', 403],
    ['committee_member', 403], ['reporter', 403], ['dept_manager', 403],
]);

test('custom incident periods use Baghdad half-open date bounds', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    foreach (['2026-10-01 00:00:00', '2026-10-02 00:00:00'] as $time) {
        DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
            'opened_by_user_id' => $admin->id, 'violation_time' => $time, 'violation_type' => 'تأخير']);
    }
    $filters = DashboardFilters::fromInput($admin, ['period' => 'custom', 'from' => '2026-10-01', 'to' => '2026-10-01']);
    $metrics = app(CaseMetrics::class);
    expect(collect($metrics->hotspots($admin, $filters)['violation'])->firstWhere('key', 'تأخير')['count'])->toBe(1)
        ->and($metrics->hotspotQuery($admin, $filters, 'violation', 'تأخير')->count())->toBe(1);
});

test('branch filtering applies to counters, hotspots, and current alerts', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $first = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'defense_letter_issued_at' => now()->subDays(14)]);
    $second = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'defense_letter_issued_at' => now()->subDays(14)]);
    $filters = DashboardFilters::fromInput($admin, ['period' => '6', 'branch' => $first->branch_id]);
    $metrics = app(CaseMetrics::class);
    expect($metrics->counts($admin, $filters)['open'])->toBe(1)
        ->and($metrics->metricQuery($admin, $filters, 'open')->sole()->id)->toBe($first->id)
        ->and(collect($metrics->hotspots($admin, $filters)['branch'])->first()['count'])->toBe(1)
        ->and(collect($metrics->alerts($admin, $filters))->pluck('case.id')->unique()->all())->not->toContain($second->id);
});
