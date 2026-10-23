<?php

use App\Enums\UserRole;
use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Punch;
use App\Models\Attendance\Transaction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestDay;
use App\Models\User;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\DayReview;
use App\Services\Attendance\OfficePolicy;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Employees\EmployeeHistory;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 12:00:00');
    Queue::fake([AssessDaysJob::class, ReconcileLeaveJob::class]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function shiftFixture(string $pattern = 'shift'): array
{
    $company = Company::factory()->create();

    return app(TenantContext::class)->runForCompany($company, function () use ($company, $pattern): array {
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $employee = Employee::factory()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'hire_date' => '2020-01-01',
            'work_pattern' => $pattern, 'rest_days' => $pattern === 'security' ? [5] : null,
        ]);
        $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
        $batch = ImportBatch::factory()->create(['company_id' => $company->id]);
        CoverageInterval::factory()->create([
            'company_id' => $company->id, 'batch_id' => $batch->id, 'branch_id' => $branch->id,
            'coverage_from' => '2026-10-18 00:00:00', 'coverage_to' => '2026-10-21 02:00:00',
            'is_confirmed' => true,
        ]);
        app(OfficePolicy::class)->createDefault($company);

        return compact('company', 'branch', 'employee', 'hr', 'batch');
    });
}

function storedShiftPunch(array $fixture, string $at, string $role): void
{
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $at, $role): void {
        $device = Device::factory()->create(['company_id' => $fixture['company']->id]);
        $transaction = Transaction::factory()->create([
            'company_id' => $fixture['company']->id, 'first_batch_id' => $fixture['batch']->id,
            'device_id' => $device->id, 'serial_number' => $device->serial_number,
            'person_code' => $fixture['employee']->employee_code, 'punched_at' => $at,
            'punch_date' => substr($at, 0, 10), 'punch_time' => substr($at, 11, 5),
            'punch_state' => $role === 'check_in' ? 'Check In' : 'Check Out',
        ]);
        Punch::factory()->create([
            'company_id' => $fixture['company']->id, 'transaction_id' => $transaction->id,
            'run_id' => ProcessingRun::factory()->create(['company_id' => $fixture['company']->id])->id,
            'person_code' => $fixture['employee']->employee_code, 'employee_id' => $fixture['employee']->id,
            'punched_at' => $at, 'role' => $role,
        ]);
    });
}

function shiftAssess(array $fixture): Day
{
    return app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): Day {
        app(DayAssessor::class)->run($fixture['company'], [
            'employee_ids' => [$fixture['employee']->id], 'from' => '2026-10-19', 'to' => '2026-10-20',
        ], 'test');

        return Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereDate('work_date', '2026-10-19')->whereNull('superseded_by_run_id')->firstOrFail();
    });
}

test('the evening checkout belongs only to its shift start date', function (): void {
    $fixture = shiftFixture();
    storedShiftPunch($fixture, '2026-10-19 16:00:00', 'check_in');
    storedShiftPunch($fixture, '2026-10-20 00:00:00', 'check_out');
    $evening = shiftAssess($fixture);

    expect($evening->status)->toBe('present')
        ->and($evening->shift_start)->toBe('16:00:00')
        ->and($evening->presence_minutes)->toBe(480)
        ->and($evening->last_out->format('Y-m-d H:i'))->toBe('2026-10-20 00:00');
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $next = Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereDate('work_date', '2026-10-20')->whereNull('superseded_by_run_id')->firstOrFail();
        expect($next->first_in)->toBeNull()->and($next->last_out)->toBeNull();
        $summary = app(AttendanceSummary::class)->totals(ReportFilters::fromArray($fixture['company'], [
            'from' => '2026-10-19', 'to' => '2026-10-19',
        ]));
        expect($summary['days']['present'])->toBe(1)->and($summary['office_employees_assessed'])->toBe(0);
    });
});

test('a half-day leave informs inference only when the punches locate its edge', function (): void {
    $fixture = shiftFixture();
    storedShiftPunch($fixture, '2026-10-19 12:00:00', 'check_in');
    storedShiftPunch($fixture, '2026-10-19 16:00:00', 'check_out');
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        RequestDay::factory()->create([
            'company_id' => $fixture['company']->id, 'employee_id' => $fixture['employee']->id,
            'work_date' => '2026-10-19', 'minutes' => 240,
        ]);
    });

    $day = shiftAssess($fixture);
    expect($day->status)->toBe('present')->and($day->shift_start)->toBe('08:00:00')
        ->and($day->shift_confidence)->toBe('leave_explained')
        ->and($day->shift_evidence['approved_leave_minutes'])->toBe(240);
});

test('an HR midnight checkout belongs to the evening work date and its withdrawal restores the gap', function (): void {
    $fixture = shiftFixture();
    storedShiftPunch($fixture, '2026-10-19 16:00:00', 'check_in');
    expect(shiftAssess($fixture)->status)->toBe('missing_punch');
    $this->actingAs($fixture['hr']);

    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $manual = app(DayReview::class)->addMissingTime($fixture['employee'], '2026-10-19', '00:00',
            'check_out', 'Verified midnight checkout', $fixture['hr']);
        expect($manual->punched_at->format('Y-m-d H:i'))->toBe('2026-10-20 00:00')
            ->and(shiftAssess($fixture)->status)->toBe('present');
        app(DayReview::class)->withdraw($manual, 'Incorrect correction', $fixture['hr']);
        expect(shiftAssess($fixture)->status)->toBe('missing_punch');
    });
});

test('an ambiguous shift is resolved by an audited HR choice and can be withdrawn', function (): void {
    $fixture = shiftFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        app(OfficePolicy::class)->add($fixture['company'], ['shift_starts' => ['08:00', '12:00']],
            '2026-10-19', $fixture['hr'], 'Test adjacent shifts');
    });
    storedShiftPunch($fixture, '2026-10-19 10:00:00', 'check_in');
    storedShiftPunch($fixture, '2026-10-19 18:00:00', 'check_out');
    $day = shiftAssess($fixture);
    expect($day->status)->toBe('shift_unresolved')->and($day->late_minutes)->toBeNull();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $totals = app(AttendanceSummary::class)->totals(ReportFilters::fromArray($fixture['company'], [
            'from' => '2026-10-19', 'to' => '2026-10-19',
        ]));
        expect($totals['days']['shift_unresolved'])->toBe(1)->and($totals['assessed_working_days'])->toBe(0);
    });

    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $day): void {
        $choice = app(DayReview::class)->pickShift($fixture['employee'], '2026-10-19', '08:00', 'Verified shift', $fixture['hr']);
        $picked = shiftAssess($fixture);
        expect($picked->shift_source)->toBe('hr')->and($picked->reviewed)->toBeTrue()->and($picked->status)->toBe('present')
            ->and($picked->late_minutes)->toBe(120)->and($day->fresh()->superseded_by_run_id)->not->toBeNull();
        app(DayReview::class)->withdraw($choice, 'Choice withdrawn', $fixture['hr']);
        expect(shiftAssess($fixture)->status)->toBe('shift_unresolved');
    });
});

test('a night outage is grouped and security without an individual rest day is not assessed', function (): void {
    $fixture = shiftFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $device = Device::factory()->create(['company_id' => $fixture['company']->id, 'status' => 'approved']);
        DeviceSetting::factory()->create([
            'company_id' => $fixture['company']->id, 'device_id' => $device->id,
            'branch_id' => $fixture['branch']->id, 'effective_from' => '2026-10-18', 'role' => 'check_in',
        ]);
    });
    expect(shiftAssess($fixture)->status)->toBe('device_outage');
    app(TenantContext::class)->runForCompany($fixture['company'], function (): void {
        expect(DataIssue::query()->where('type', 'device_outage')->count())->toBeGreaterThan(0);
    });

    $security = shiftFixture('security');
    app(TenantContext::class)->runForCompany($security['company'], function () use ($security): void {
        app(EmployeeHistory::class)->record($security['employee'], ['rest_days' => null],
            '2026-10-19', 'manual', $security['hr']);
    });
    expect(shiftAssess($security)->status)->toBe('pattern_missing');
});

test('approval after the start of a night window cannot create an earlier outage', function (): void {
    $fixture = shiftFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $device = Device::factory()->create(['company_id' => $fixture['company']->id, 'status' => 'approved']);
        DeviceSetting::factory()->create([
            'company_id' => $fixture['company']->id, 'device_id' => $device->id,
            'branch_id' => $fixture['branch']->id, 'effective_from' => '2026-10-20', 'role' => 'check_in',
        ]);
    });

    expect(shiftAssess($fixture)->status)->toBe('absent');
    app(TenantContext::class)->runForCompany($fixture['company'], function (): void {
        expect(DataIssue::query()->where('subject_key', 'like', '%:2026-10-19')->exists())->toBeFalse();
    });
});

test('another company cannot assess or choose a shift', function (): void {
    $first = shiftFixture();
    $other = shiftFixture();
    $this->actingAs($other['hr']);
    app(TenantContext::class)->runForCompany($first['company'], function () use ($first, $other): void {
        expect(fn () => app(DayAssessor::class)->run($other['company'], [
            'employee_ids' => [$other['employee']->id], 'from' => '2026-10-19', 'to' => '2026-10-19',
        ], 'test'))->toThrow(HttpException::class)
            ->and(fn () => app(DayReview::class)->pickShift($first['employee'], '2026-10-19', '16:00', 'Wrong tenant', $other['hr']))
            ->toThrow(HttpException::class);
    });
});

test('office presence below nine hours alerts while a full leave day settles eight hours of absence', function (): void {
    $fixture = shiftFixture('office');
    storedShiftPunch($fixture, '2026-10-19 08:00:00', 'check_in');
    storedShiftPunch($fixture, '2026-10-19 16:30:00', 'check_out');
    $present = shiftAssess($fixture);
    expect($present->status)->toBe('present')->and($present->presence_minutes)->toBe(510)
        ->and($present->short_minutes)->toBe(30);

    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $absent = Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereDate('work_date', '2026-10-20')->whereNull('superseded_by_run_id')->firstOrFail();
        expect($absent->status)->toBe('absent');
        $type = LeaveType::factory()->create(['name' => 'Annual Leave', 'normalized_key' => 'annual leave|',
            'covers' => true, 'is_mapped' => true]);
        LeaveRequest::factory()->create([
            'employee_id' => $fixture['employee']->id, 'owner_code' => $fixture['employee']->employee_code,
            'leave_type_id' => $type->id, 'status' => 'approved',
            'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'duration_days' => '1.0',
        ]);
        app(Reconciler::class)->run($fixture['company'], [$fixture['employee']->id], 'test');
        $summary = app(AttendanceSummary::class)->totals(ReportFilters::fromArray($fixture['company'], [
            'from' => '2026-10-20', 'to' => '2026-10-20',
        ]));
        expect((int) Allocation::query()->where('target_day_id', $absent->id)->sum('minutes'))->toBe(480)
            ->and($summary['minutes']['absence'])->toBe([
                'gross' => 480, 'reconciled_by_leave' => 480, 'reconciled_by_credit' => 0, 'remaining' => 0,
            ]);
        Queue::assertPushed(AssessDaysJob::class, 1);
        app(Reconciler::class)->run($fixture['company'], [$fixture['employee']->id], 'replay');
        Queue::assertPushed(AssessDaysJob::class, 1);
    });
});
