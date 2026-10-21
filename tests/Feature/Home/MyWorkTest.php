<?php

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\DocumentApproval;
use App\Models\Employee;
use App\Models\HrPartner;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Services\Home\MyWork;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

test('new reports lead HR actions and have a counter linked to the inbox', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    DisciplinaryCase::factory()->create(['company_id' => $company->id, 'law_version_id' => Law::factory()->create()->id, 'status' => CaseStatus::Open]);
    $report = ViolationReport::factory()->create(['company_id' => $company->id, 'status' => 'received']);

    $work = app(MyWork::class)->for($hr);
    expect($work['actions']->first()['report']->id)->toBe($report->id)
        ->and($work['new_reports_count'])->toBe(1);
    $this->actingAs($hr)->get('/home')->assertOk()->assertSee(__('New reports'))->assertSee('R-0001');
});

test('reporter home shows only their bare report status', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $other = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    $report = ViolationReport::factory()->create(['reporter_user_id' => $reporter->id, 'status' => 'received']);
    ViolationReport::factory()->create(['reporter_user_id' => $other->id, 'status' => 'received']);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $other->id]);
    $name = $case->employee->name;

    $work = app(MyWork::class)->for($reporter);
    expect($work['role'])->toBe('reporter')
        ->and($work['reports']->pluck('id')->all())->toBe([$report->id]);
    $this->actingAs($reporter)->get('/home')->assertOk()->assertSee(__('My reports'))
        ->assertDontSee($name)->assertDontSee('#'.$case->case_number);
});

test('HR home keeps case actions scoped and query count bounded as cases grow', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $other = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $law = Law::factory()->create();
    DisciplinaryCase::factory()->count(50)->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'employee_id' => $employee->id, 'law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $hr->id]);
    $hidden = DisciplinaryCase::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'employee_id' => $employee->id, 'law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $other->id]);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });
    $work = app(MyWork::class)->for($hr);
    expect($work['actions'])->toHaveCount(50)
        ->and($work['actions']->pluck('case.id')->all())->not->toContain($hidden->id)
        ->and($queries)->toBeLessThan(60);
});

test('every workspace role can open home and platform admin returns to its panel', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    foreach ([UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::Reporter,
        UserRole::DeptManager, UserRole::CommitteeMember] as $role) {
        $user = User::factory()->create(['company_id' => $company->id, 'primary_role' => $role]);
        $this->actingAs($user)->get('/home')->assertOk();
    }
    $reviewer = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::ExternalLegalReviewer,
        'law_firm_partner_id' => LawFirmPartner::factory()->create()->id]);
    $partner = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::HrPartnerStaff,
        'hr_partner_id' => HrPartner::factory()->create()->id]);
    $this->actingAs($reviewer)->get('/home')->assertOk();
    $this->actingAs($partner)->get('/home')->assertOk();
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($platform)->get('/home')->assertRedirect('/platform');
});

test('home renders its action heading in English and Arabic', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($hr);
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $this->get('/home')->assertOk()->assertSee(__('messages.home.needs_action'));
    }
    app()->setLocale('en');
});

test('pool tasks disappear after their category approves, including for an HR pool member', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id,
        'law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::RecommendationsAwaitingApprovals,
        'defense_letter_issued_at' => now()->subDay(), 'opened_by_user_id' => $hr->id]);
    $mandate = CommitteeMandate::factory()->create(['company_id' => $company->id,
        'effective_from' => now()->subDays(2)]);
    $member = CommitteePoolMember::factory()->create(['company_id' => $company->id,
        'mandate_id' => $mandate->id, 'role_type' => 'hr', 'user_id' => $hr->id]);
    $document = Document::factory()->create(['company_id' => $company->id, 'case_id' => $case->id,
        'document_type' => 'recommendations', 'status' => 'ready_for_review', 'is_locked' => false]);

    expect(app(MyWork::class)->for($hr)['actions']->pluck('action.url')->all())
        ->toContain('/committee/approvals/'.$document->id);
    DocumentApproval::factory()->create(['company_id' => $company->id, 'document_id' => $document->id,
        'committee_pool_member_id' => $member->id, 'approver_user_id' => $hr->id,
        'role_category' => 'hr', 'decision' => 'approved']);
    expect(app(MyWork::class)->for($hr)['actions']->pluck('action.url')->all())
        ->not->toContain('/committee/approvals/'.$document->id);
    $conflicted = User::factory()->create(['company_id' => $company->id,
        'primary_role' => UserRole::CommitteeMember]);
    CommitteePoolMember::factory()->create(['company_id' => $company->id,
        'mandate_id' => $mandate->id, 'role_type' => 'legal', 'user_id' => $conflicted->id,
        'employee_id' => $case->employee_id]);
    expect(app(MyWork::class)->for($conflicted)['documents'])->toBeEmpty();
});

test('reviewer work follows a pending consultation even when the case has another status', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $firm = LawFirmPartner::factory()->create();
    DB::table('company_law_firm_partner')->insert(['company_id' => $company->id,
        'law_firm_partner_id' => $firm->id]);
    $reviewer = User::factory()->create(['company_id' => null,
        'primary_role' => UserRole::ExternalLegalReviewer, 'law_firm_partner_id' => $firm->id]);
    $case = DisciplinaryCase::factory()->create(['company_id' => $company->id,
        'law_version_id' => Law::factory()->create()->id,
        'status' => CaseStatus::DecisionLetterReadyForReview,
        'legal_review_requested_at' => now()->subDay()]);

    expect(app(MyWork::class)->for($reviewer)['cases']->pluck('id')->all())->toBe([$case->id]);
    $this->actingAs($reviewer)->get('/home')->assertOk()->assertSee('/cases/'.$case->id.'/consultation');
});

test('the 48-hour tile and its drill-down share the Baghdad end-of-day boundary', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    DisciplinaryCase::factory()->create(['company_id' => $company->id,
        'law_version_id' => Law::factory()->create()->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $hr->id, 'defense_letter_issued_at' => '2026-10-01 08:00:00']);
    $metrics = app(CaseMetrics::class);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 23:58:00', 'Asia/Baghdad'));
    $filters = DashboardFilters::fromInput($hr, ['period' => '6']);
    expect($metrics->metricQuery($hr, $filters, 'procedure_due_48h')->count())->toBe(0);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 23:59:59', 'Asia/Baghdad'));
    expect($metrics->counts($hr, $filters)['procedure_due_48h'])->toBe(1)
        ->and($metrics->metricQuery($hr, $filters, 'procedure_due_48h')->count())->toBe(1);
    CarbonImmutable::setTestNow();
});
