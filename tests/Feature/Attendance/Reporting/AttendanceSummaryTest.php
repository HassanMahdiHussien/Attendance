<?php

use App\Enums\UserRole;
use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\Meal;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\Setting;
use App\Models\User;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\DayReview;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Employees\EmployeeHistory;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 12:00:00');
    Queue::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function reportingFixture(): array
{
    $company = Company::factory()->create();

    return app(TenantContext::class)->runForCompany($company, function () use ($company): array {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'employee_code' => '50100001', 'hire_date' => '2020-01-01', 'work_pattern' => 'office',
        ]);
        $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
        $type = LeaveType::factory()->create(['name' => 'Annual Leave', 'normalized_key' => 'annual leave|', 'covers' => true, 'is_mapped' => true]);

        return compact('company', 'branch', 'employee', 'hr', 'type');
    });
}

function reportingFilters(array $fixture, array $values = []): ReportFilters
{
    return ReportFilters::fromArray($fixture['company'], $values + ['from' => '2026-10-19', 'to' => '2026-10-20']);
}

test('real assessment and reconciliation give one absent category with separate HR and leave flags', function (): void {
    $fixture = reportingFixture();
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $batch = ImportBatch::factory()->create();
        CoverageInterval::factory()->create([
            'batch_id' => $batch->id, 'branch_id' => $fixture['branch']->id,
            'coverage_from' => '2026-10-19 00:00:00', 'coverage_to' => '2026-10-20 23:59:59',
            'is_confirmed' => true,
        ]);
        app(DayAssessor::class)->run($fixture['company'], [
            'employee_ids' => [$fixture['employee']->id], 'from' => '2026-10-19', 'to' => '2026-10-19',
        ], 'report_test');
        app(DayReview::class)->confirmAbsence($fixture['employee'], '2026-10-19', 'Verified', $fixture['hr']);
        app(DayAssessor::class)->run($fixture['company'], [
            'employee_ids' => [$fixture['employee']->id], 'from' => '2026-10-19', 'to' => '2026-10-19',
        ], 'report_test');
        LeaveRequest::factory()->create([
            'employee_id' => $fixture['employee']->id, 'owner_code' => $fixture['employee']->employee_code,
            'leave_type_id' => $fixture['type']->id, 'external_id' => 'FORM-501-A',
            'status' => 'approved', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'duration_days' => '1.0',
        ]);
        app(Reconciler::class)->run($fixture['company'], [$fixture['employee']->id], 'report_test');
        Day::factory()->create([
            'employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
            'work_date' => '2026-10-20', 'status' => 'pending',
        ]);

        $summary = app(AttendanceSummary::class);
        $filters = reportingFilters($fixture);
        $totals = $summary->totals($filters);
        $days = iterator_to_array($summary->perDay($filters, $fixture['employee']->id));
        expect($totals['assessed_working_days'])->toBe(1)
            ->and($totals['days']['absent'])->toBe(1)
            ->and($totals['days']['pending'])->toBe(1)
            ->and($totals['minutes']['absence'])->toBe([
                'gross' => 480, 'reconciled_by_leave' => 480, 'reconciled_by_credit' => 0, 'remaining' => 0,
            ])
            ->and($totals['flags']['hr_confirmed_absence'])->toBe(1)
            ->and($totals['flags']['absence_closed_by_request'])->toBe(1)
            ->and($totals['leave']['Annual Leave|approved'])->toBe(480)
            ->and($totals['leave_day_equivalents']['Annual Leave|approved'])->toBe(1.0)
            ->and($days[0]['minutes']['absence'])->toBe($totals['minutes']['absence'])
            ->and(iterator_to_array($summary->drillDownDayIds($filters, 'absent')))->toBe([$days[0]['day_id']]);
    });
});

test('credit is counted once and its available balance is measured at period end', function (): void {
    $fixture = reportingFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        Day::factory()->create(['employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
            'work_date' => '2026-10-19', 'status' => 'present', 'early_minutes' => 180]);
        Day::factory()->create(['employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
            'work_date' => '2026-10-20', 'status' => 'present', 'late_minutes' => 15]);
        LeaveRequest::factory()->create([
            'employee_id' => $fixture['employee']->id, 'owner_code' => $fixture['employee']->employee_code,
            'leave_type_id' => $fixture['type']->id, 'status' => 'approved',
            'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'duration_days' => '0.5',
        ]);
        app(Reconciler::class)->run($fixture['company'], [$fixture['employee']->id], 'report_test');

        $summary = app(AttendanceSummary::class);
        $first = $summary->totals(reportingFilters($fixture, ['to' => '2026-10-19']));
        $both = $summary->totals(reportingFilters($fixture));
        app(Reconciler::class)->run($fixture['company'], [$fixture['employee']->id], 'report_test');
        $replayed = $summary->totals(reportingFilters($fixture));
        expect($first['credits'])->toBe(['created' => 60, 'used_in_period' => 0, 'available_at_period_end' => 60])
            ->and($both['credits'])->toBe(['created' => 60, 'used_in_period' => 15, 'available_at_period_end' => 45])
            ->and($replayed['credits'])->toBe($both['credits'])
            ->and($replayed['minutes'])->toBe($both['minutes'])
            ->and($both['minutes']['early'])->toBe([
                'gross' => 180, 'reconciled_by_leave' => 180, 'reconciled_by_credit' => 0, 'remaining' => 0,
            ])
            ->and($both['minutes']['late'])->toBe([
                'gross' => 15, 'reconciled_by_leave' => 0, 'reconciled_by_credit' => 15, 'remaining' => 0,
            ]);
    });
});

test('a half-day leave keeps the uncovered half of an absence visible', function (): void {
    $fixture = reportingFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        Day::factory()->create(['employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
            'work_date' => '2026-10-19', 'status' => 'absent']);
        LeaveRequest::factory()->create([
            'employee_id' => $fixture['employee']->id, 'owner_code' => $fixture['employee']->employee_code,
            'leave_type_id' => $fixture['type']->id, 'status' => 'approved',
            'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'duration_days' => '0.5',
        ]);
        app(Reconciler::class)->run($fixture['company'], [$fixture['employee']->id], 'report_test');
        $totals = app(AttendanceSummary::class)->totals(reportingFilters($fixture));
        expect($totals['minutes']['absence'])->toBe([
            'gross' => 480, 'reconciled_by_leave' => 240, 'reconciled_by_credit' => 0, 'remaining' => 240,
        ])->and($totals['leave_day_equivalents']['Annual Leave|approved'])->toBe(0.5)
            ->and($totals['flags']['absence_closed_by_request'])->toBe(0);
    });
});

test('expired credit has no period-end availability and pending leave does not cover a day', function (): void {
    $fixture = reportingFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        Setting::factory()->create(['credit_expiry_days' => 0]);
        Day::factory()->create(['employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
            'work_date' => '2026-10-19', 'status' => 'present']);
        LeaveRequest::factory()->create([
            'employee_id' => $fixture['employee']->id, 'owner_code' => $fixture['employee']->employee_code,
            'leave_type_id' => $fixture['type']->id, 'status' => 'approved',
            'start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'duration_days' => '0.5',
        ]);
        foreach (['pending', 'draft', 'rejected'] as $status) {
            LeaveRequest::factory()->create([
                'employee_id' => $fixture['employee']->id, 'owner_code' => $fixture['employee']->employee_code,
                'leave_type_id' => $fixture['type']->id, 'status' => $status,
                'start_date' => '2026-10-19', 'end_date' => '2026-10-19',
            ]);
        }
        app(Reconciler::class)->run($fixture['company'], [$fixture['employee']->id], 'report_test');
        $totals = app(AttendanceSummary::class)->totals(reportingFilters($fixture));
        expect($totals['credits']['created'])->toBe(240)
            ->and($totals['credits']['available_at_period_end'])->toBe(0)
            ->and($totals['flags']['nothing_to_reconcile'])->toBe(1)
            ->and($totals['pending_leave_requests']['draft'])->toBe(1)
            ->and($totals['pending_leave_requests']['pending'])->toBe(1)
            ->and($totals['pending_leave_requests']['rejected'])->toBe(1)
            ->and($totals['leave'])->toBe(['Annual Leave|approved' => 240]);
    });
});

test('historical branches, meals and attributable issues are scoped without exposing another company', function (): void {
    $fixture = reportingFixture();
    $other = reportingFixture();
    app(TenantContext::class)->runForCompany($other['company'], function () use ($other): void {
        Day::factory()->create(['employee_id' => $other['employee']->id, 'branch_id' => $other['branch']->id,
            'work_date' => '2026-10-19', 'status' => 'absent']);
    });
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $other): void {
        $laterBranch = Branch::factory()->create();
        $old = Day::factory()->create(['employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
            'work_date' => '2026-10-19', 'status' => 'absent']);
        Day::factory()->create(['employee_id' => $fixture['employee']->id, 'branch_id' => $laterBranch->id,
            'work_date' => '2026-10-20', 'status' => 'present']);
        Meal::factory()->create(['employee_id' => $fixture['employee']->id, 'meal_date' => '2026-10-19']);
        Meal::factory()->create(['employee_id' => null, 'meal_date' => '2026-10-19']);
        DataIssue::factory()->create(['subject_key' => $fixture['employee']->employee_code]);
        DataIssue::factory()->create(['subject_key' => 'UNLINKED-501']);
        DataIssue::factory()->create(['type' => 'device_outage', 'subject_type' => 'branch_date',
            'subject_key' => 'branch:'.$fixture['branch']->id.':2026-10-19']);

        $summary = app(AttendanceSummary::class);
        $all = $summary->totals(reportingFilters($fixture));
        $oldBranch = $summary->totals(reportingFilters($fixture, ['branch_ids' => [$fixture['branch']->id]]));
        expect($all['days']['absent'])->toBe(1)->and($all['days']['present'])->toBe(1)
            ->and($oldBranch['days']['absent'])->toBe(1)->and($oldBranch['days']['present'])->toBe(0)
            ->and($all['meals']['employee'])->toBe(1)->and($all['meals']['non_employee'])->toBe(1)
            ->and($all['meals']['non_employee_by_department']['Operations'])->toBe(1)
            ->and($all['issues']['attributable']['unknown_person'])->toBe(1)
            ->and($all['issues']['company_wide']['unknown_person'])->toBe(1)
            ->and($oldBranch['issues']['company_wide']['device_outage'])->toBe(1)
            ->and(iterator_to_array($summary->drillDownDayIds(reportingFilters($fixture), 'absent')))->toBe([$old->id]);
        // Another company's employee is simply not found inside this company's scope.
        expect(fn () => $summary->perDay(reportingFilters($fixture), $other['employee']->id)->current())->toThrow(ModelNotFoundException::class);
        $filters = reportingFilters($fixture);
        app(TenantContext::class)->runForCompany($other['company'], function () use ($summary, $filters): void {
            expect(fn () => $summary->totals($filters))->toThrow(HttpException::class);
        });
    });
});

test('only completed working-day categories enter the assessed denominator', function (): void {
    $fixture = reportingFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        foreach (['missing_punch', 'pending', 'rest_day', 'device_outage', 'pattern_missing'] as $offset => $status) {
            Day::factory()->create([
                'employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
                'work_date' => Carbon::parse('2026-10-18')->addDays($offset)->toDateString(), 'status' => $status,
                'late_minutes' => $status === 'missing_punch' ? 15 : null,
            ]);
        }
        $totals = app(AttendanceSummary::class)->totals(ReportFilters::fromArray($fixture['company'], [
            'from' => '2026-10-18', 'to' => '2026-10-22',
        ]));
        expect($totals['assessed_working_days'])->toBe(1)
            ->and($totals['days']['missing_punch'])->toBe(1)
            ->and($totals['days']['pending'])->toBe(1)
            ->and($totals['days']['rest_day'])->toBe(1)
            ->and($totals['days']['device_outage'])->toBe(1)
            ->and($totals['days']['pattern_missing'])->toBe(1)
            ->and($totals['observed_minutes']['late'])->toBe(15)
            ->and($totals['minutes']['late']['gross'])->toBe(0)
            ->and(iterator_to_array(app(AttendanceSummary::class)->drillDownDayIds(ReportFilters::fromArray($fixture['company'], [
                'from' => '2026-10-18', 'to' => '2026-10-22',
            ]), 'pending')))->toHaveCount($totals['days']['pending']);
    });
});

test('a back-dated branch change is reported under each days historical branch', function (): void {
    $fixture = reportingFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $laterBranch = Branch::factory()->create();
        app(EmployeeHistory::class)->record($fixture['employee'], ['branch_id' => $laterBranch->id],
            '2026-10-20', 'manual');
        $batch = ImportBatch::factory()->create();
        foreach ([$fixture['branch'], $laterBranch] as $branch) {
            CoverageInterval::factory()->create(['batch_id' => $batch->id, 'branch_id' => $branch->id,
                'coverage_from' => '2026-10-19 00:00:00', 'coverage_to' => '2026-10-20 23:59:59',
                'is_confirmed' => true]);
        }
        app(DayAssessor::class)->run($fixture['company'], [
            'employee_ids' => [$fixture['employee']->id], 'from' => '2026-10-19', 'to' => '2026-10-20',
        ], 'report_test');
        $summary = app(AttendanceSummary::class);
        $old = $summary->totals(reportingFilters($fixture, ['branch_ids' => [$fixture['branch']->id]]));
        $new = $summary->totals(reportingFilters($fixture, ['branch_ids' => [$laterBranch->id]]));
        expect($old['days']['absent'])->toBe(1)->and($new['days']['absent'])->toBe(1)
            ->and(collect(iterator_to_array($summary->perDay(reportingFilters($fixture), $fixture['employee']->id)))
                ->pluck('branch_id')->all())->toBe([$fixture['branch']->id, $laterBranch->id]);
    });
});

test('department, manager, work pattern and status filters use the day inputs', function (): void {
    $fixture = reportingFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $manager = Employee::factory()->create(['branch_id' => $fixture['branch']->id]);
        app(EmployeeHistory::class)->record($fixture['employee'], [
            'department' => 'Operations', 'manager_employee_id' => $manager->id,
        ], '2026-10-19', 'manual');
        app(EmployeeHistory::class)->record($fixture['employee'], [
            'department' => 'Finance', 'manager_employee_id' => null,
        ], '2026-10-20', 'manual');
        Day::factory()->create([
            'employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
            'work_date' => '2026-10-19', 'status' => 'present',
            'inputs' => ['work_pattern' => 'office'],
        ]);
        Day::factory()->create([
            'employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
            'work_date' => '2026-10-20', 'status' => 'absent',
            'inputs' => ['work_pattern' => 'office'],
        ]);
        $filters = reportingFilters($fixture, [
            'departments' => ['Operations'], 'manager_employee_ids' => [$manager->id],
            'work_patterns' => ['office'], 'statuses' => ['present'],
        ]);
        $totals = app(AttendanceSummary::class)->totals($filters);
        expect($totals['days']['present'])->toBe(1)->and($totals['days']['absent'])->toBe(0)
            ->and(iterator_to_array(app(AttendanceSummary::class)->drillDownDayIds($filters, 'present')))->toHaveCount(1);
    });
});
