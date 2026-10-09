<?php

use App\Enums\CaseStatus;
use App\Models\CaseConflictClearance;
use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Law;
use App\Models\LawJurisdiction;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\ConflictClearance;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

test('opening links prior cases and exposes duplicate and responsible-person patterns', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create(['category' => 'labor', 'approval_status' => 'approved']);
    LawJurisdiction::create(['law_id' => $law->id, 'jurisdiction' => 'federal_iraq']);
    $employee = Employee::factory()->create();
    $responsible = Employee::factory()->create(['branch_id' => $employee->branch_id]);
    $now = CarbonImmutable::now();
    foreach ([8, 5, 2] as $months) {
        DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
            'law_version_id' => $law->id, 'status' => CaseStatus::ArchivedFinal,
            'violation_type' => $months === 8 ? 'lateness' : 'other', 'created_at' => $now->subMonths($months),
            'opened_by_user_id' => $hr->id]);
    }
    for ($i = 0; $i < 2; $i++) {
        DisciplinaryCase::factory()->create(['branch_id' => $employee->branch_id,
            'law_version_id' => $law->id, 'status' => CaseStatus::Open,
            'alleged_responsible_employee_id' => $responsible->id, 'opened_by_user_id' => $hr->id]);
    }
    $case = DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
        'violation_type' => 'lateness', 'violation_place_freetext' => 'Gate', 'opened_by_user_id' => $hr->id,
        'alleged_responsible_employee_id' => $responsible->id, 'violation_time' => $now]);
    $report = ViolationReport::factory()->create(['employee_id' => $employee->id,
        'branch_id' => $employee->branch_id, 'occurred_at' => $now->addDay()]);
    $insights = app(CaseInsights::class);
    expect($insights->duplicateCandidates($case)['reports']->pluck('id')->all())->toContain($report->id);
    completeCompanySetup($company);
    $opened = app(CaseOpening::class)->open($case, $hr);
    expect($opened->status)->toBe(CaseStatus::Open);
    expect($opened->law_version_id)->toBe($law->id);
    expect($insights->priorBanner($opened)['count'])->toBe(3);
    expect(collect($insights->priorBanner($opened)['cases'])->where('same_type_repeat', true)->count())->toBe(1);
    expect($insights->responsiblePersonPattern($opened))->toBe(['count' => 2, 'flagged' => true]);
    expect(DB::table('case_links')->where('case_id', $case->id)->where('link_type', 'prior_record')->count())->toBe(3);
    $duplicate = DisciplinaryCase::factory()->create(['employee_id' => $employee->id,
        'branch_id' => $employee->branch_id, 'violation_time' => $now->addHours(12),
        'opened_by_user_id' => $hr->id]);
    expect($insights->duplicateCandidates($case)['cases']->pluck('id')->all())->toContain($duplicate->id);
    $insights->linkDuplicate($case, $duplicate);
    expect(DB::table('case_links')->where('case_id', $case->id)->where('link_type', 'duplicate_merge')->count())->toBe(1);
});

test('name-only conflicts require an audited HR clearance and employee-linked conflicts cannot be cleared', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $case = DisciplinaryCase::factory()->create(['opened_by_user_id' => $hr->id]);
    $mandate = CommitteeMandate::factory()->create(['effective_from' => now()->subDay()]);
    $nameOnly = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id,
        'person_name' => $case->employee->name, 'employee_id' => null]);
    $linked = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id,
        'person_name' => $case->employee->name, 'employee_id' => $case->employee_id]);
    $service = app(ConflictClearance::class);
    expect(collect($service->conflicts($case))->where('excluded', true)->count())->toBe(2);
    expect(fn () => $service->clear($case, $linked, 'Checked identity', $hr))->toThrow(ValidationException::class);
    $service->clear($case, $nameOnly, 'Checked identity records', $hr);
    expect(collect($service->conflicts($case))->where('excluded', true)->count())->toBe(1);
    expect($case->events()->where('event_type', 'conflict_cleared')->count())->toBe(1);
    $otherCompany = Company::factory()->create();
    app(TenantContext::class)->setCompany($otherCompany);
    expect(CaseConflictClearance::query()->count())->toBe(0);
});
