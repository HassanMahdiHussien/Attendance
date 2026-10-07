<?php

use App\Enums\CaseStatus;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\HrPartner;
use App\Models\Law;
use App\Models\User;
use App\Services\Cases\CaseAlertDispatcher;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Support\Access\CaseVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('dashboard counts use exactly the drill-down predicates and exclude other companies', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'violation_type' => 'تأخير',
        'defense_letter_issued_at' => now()->subDays(5)]);
    $other = Company::factory()->create();
    app(TenantContext::class)->setCompany($other);
    $outsider = User::factory()->create(['company_id' => $other->id, 'primary_role' => 'company_admin']);
    app(TenantContext::class)->setCompany($company);
    $filters = DashboardFilters::fromInput($admin, ['period' => '6']);
    $metrics = app(CaseMetrics::class);
    $counts = $metrics->counts($admin, $filters);
    expect($counts['open'])->toBe(1);
    foreach (['open', 'closed_month', 'recommendation_only', 'warning', 'termination', 'within15_numerator', 'within15_denominator'] as $metric) {
        expect($counts[$metric])->toBe($metrics->metricQuery($admin, $filters, $metric)->count());
    }
    expect(CaseVisibility::for($outsider)->whereKey($case->id)->exists())->toBeFalse();
    $this->actingAs($admin);
    Livewire::test(HrDashboard::class)->assertSee('لوحة المتابعة')->set('branch', (string) $case->branch_id)->assertSee('الحالات المفتوحة');
    $this->get('/dashboard/cases?metric=open')->assertOk()->assertSee($case->employee->name);
    app(TenantContext::class)->setCompany($other);
    $this->actingAs($outsider)->get('/dashboard')->assertOk()->assertDontSee($case->employee->name);
    expect($metrics->counts($outsider, DashboardFilters::fromInput($outsider, ['period' => '6']))['open'])->toBe(0);
});

test('a stats-only partner sees aggregates but cannot open a drill-down or names', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'violation_type' => 'تأخير']);
    $employeeName = DB::table('employees')->where('id', $case->employee_id)->value('name');
    $partner = HrPartner::factory()->create();
    DB::table('company_hr_partner')->insert(['company_id' => $company->id, 'hr_partner_id' => $partner->id, 'full_case_access' => false]);
    $viewer = User::factory()->create(['company_id' => null, 'primary_role' => 'hr_partner_staff', 'hr_partner_id' => $partner->id]);
    expect(CaseVisibility::aggregateFor($viewer)->count())->toBe(1)
        ->and(CaseVisibility::for($viewer)->count())->toBe(0);
    $this->actingAs($viewer)->get('/home')->assertOk()->assertSee('تأخير')->assertDontSee($employeeName)
        ->assertDontSee('عرض الحالات')->assertDontSee('/cases/'.$case->id);
    $this->get('/home/cases/'.$company->id)->assertForbidden();
    $this->get('/dashboard/cases?metric=open')->assertForbidden();
});

test('a full-access partner can list only its opted-in company cases', function (): void {
    $company = Company::factory()->create();
    $other = Company::factory()->create();
    $law = Law::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open]);
    $employeeName = DB::table('employees')->where('id', $case->employee_id)->value('name');
    app(TenantContext::class)->setCompany($other);
    DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open]);
    $partner = HrPartner::factory()->create();
    DB::table('company_hr_partner')->insert([
        ['company_id' => $company->id, 'hr_partner_id' => $partner->id, 'full_case_access' => true],
        ['company_id' => $other->id, 'hr_partner_id' => $partner->id, 'full_case_access' => false],
    ]);
    $viewer = User::factory()->create(['company_id' => null, 'primary_role' => 'hr_partner_staff', 'hr_partner_id' => $partner->id]);
    $this->actingAs($viewer)->get('/home')->assertOk()->assertSee('/home/cases/'.$company->id)
        ->assertDontSee('/home/cases/'.$other->id);
    $this->get('/home/cases/'.$company->id)->assertOk()->assertSee($employeeName);
    $this->get('/home/cases/'.$other->id)->assertForbidden();
});

test('cancelled consultations do not produce pending alerts', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id, 'legal_review_requested_at' => now()->subDay(),
        'legal_review_cancelled_at' => now()]);
    expect(collect(app(CaseAlertDispatcher::class)->alertsFor($case))->pluck(0))->not->toContain('consultation_pending');
});

test('dashboard remains bounded with five thousand cases', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $case = DisciplinaryCase::factory()->create(['law_version_id' => $law->id, 'status' => CaseStatus::Open,
        'opened_by_user_id' => $admin->id]);
    $rows = [];
    for ($number = 10000; $number < 15000; $number++) {
        $rows[] = ['company_id' => $company->id, 'branch_id' => $case->branch_id, 'employee_id' => $case->employee_id,
            'case_number' => $number, 'status' => 'open', 'jurisdiction_at_opening' => $case->jurisdiction_at_opening->value,
            'law_version_id' => $law->id, 'opened_by_user_id' => $admin->id, 'violation_time' => now(),
            'violation_type' => 'تأخير', 'created_at' => now(), 'updated_at' => now()];
        if (count($rows) === 500) {
            DB::table('cases')->insert($rows);
            $rows = [];
        }
    }
    DB::enableQueryLog();
    $this->actingAs($admin)->get('/dashboard')->assertOk();
    expect(count(DB::getQueryLog()))->toBeLessThan(70);
    DB::disableQueryLog();
});
