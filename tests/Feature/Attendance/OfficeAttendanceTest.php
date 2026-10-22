<?php

use App\Enums\UserRole;
use App\Events\AttendanceProcessed;
use App\Events\CoverageChanged;
use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Attendance\ProcessAttendanceJob;
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
use App\Models\User;
use App\Services\Attendance\DataIssues;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\DayReview;
use App\Services\Attendance\OfficePolicy;
use App\Services\Attendance\PendingDaysScheduler;
use App\Services\Employees\EmployeeHistory;
use App\Services\Employees\ExitReview;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-22 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function officeFixture(bool $covered = true): array
{
    $company = Company::factory()->create(['created_at' => '2020-01-01 00:00:00']);
    $context = app(TenantContext::class);

    return $context->runForCompany($company, function () use ($company, $covered): array {
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $employee = Employee::factory()->create([
            'company_id' => $company->id, 'branch_id' => $branch->id,
            'hire_date' => '2020-01-01', 'work_pattern' => 'office',
        ]);
        $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
        app(OfficePolicy::class)->createDefault($company);
        $batch = ImportBatch::factory()->create(['company_id' => $company->id]);
        CoverageInterval::factory()->create([
            'company_id' => $company->id, 'batch_id' => $batch->id, 'branch_id' => $branch->id,
            'coverage_from' => '2026-10-19 00:00:00', 'coverage_to' => '2026-10-20 23:59:59',
            'is_confirmed' => $covered,
        ]);

        return compact('company', 'branch', 'employee', 'hr', 'batch');
    });
}

function officeStoredPunch(array $fixture, string $time, string $role, ?Employee $employee = null): void
{
    $employee ??= $fixture['employee'];
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $time, $role, $employee): void {
        $device = Device::factory()->create(['company_id' => $fixture['company']->id]);
        $transaction = Transaction::factory()->create([
            'company_id' => $fixture['company']->id, 'first_batch_id' => $fixture['batch']->id,
            'device_id' => $device->id, 'serial_number' => $device->serial_number,
            'person_code' => $employee->employee_code, 'punched_at' => '2026-10-19 '.$time,
            'punch_date' => '2026-10-19', 'punch_time' => substr($time, 0, 5),
            'punch_state' => $role === 'check_in' ? 'Check In' : 'Check Out',
        ]);
        Punch::factory()->create([
            'company_id' => $fixture['company']->id, 'transaction_id' => $transaction->id,
            'run_id' => ProcessingRun::factory()->create(['company_id' => $fixture['company']->id])->id,
            'person_code' => $employee->employee_code, 'employee_id' => $employee->id,
            'punched_at' => '2026-10-19 '.$time, 'role' => $role,
        ]);
    });
}

function officeAssess(array $fixture, string $from = '2026-10-19', string $to = '2026-10-19'): ?Day
{
    return app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $from, $to): ?Day {
        app(DayAssessor::class)->run($fixture['company'], [
            'branch_ids' => [$fixture['branch']->id], 'from' => $from, 'to' => $to,
        ], 'test');

        return Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereDate('work_date', '2026-10-19')->whereNull('superseded_by_run_id')->first();
    });
}

test('office minutes use the flexible arrival boundary and keep the components separate', function (): void {
    $fixture = officeFixture();
    officeStoredPunch($fixture, '09:15:00', 'check_in');
    officeStoredPunch($fixture, '17:50:00', 'check_out');

    $day = officeAssess($fixture);
    expect($day->status)->toBe('present')
        ->and($day->late_minutes)->toBe(15)
        ->and($day->early_minutes)->toBe(10)
        ->and($day->balance_minutes)->toBe(25)
        ->and($day->required_leave_at->format('H:i'))->toBe('18:00');
});

test('ongoing and uncovered days stay pending while covered no-punch days become absent', function (): void {
    $fixture = officeFixture();
    $absent = officeAssess($fixture);
    expect($absent->status)->toBe('absent');

    $uncovered = officeFixture(false);
    expect(officeAssess($uncovered)->status)->toBe('pending');

    Carbon::setTestNow('2026-10-19 19:59:00');
    expect(officeAssess($fixture)->status)->toBe('pending');
    Carbon::setTestNow('2026-10-19 20:00:00');
    expect(officeAssess($fixture)->status)->toBe('absent');
});

test('HR completes a missing punch and can reverse the correction', function (): void {
    $fixture = officeFixture();
    officeStoredPunch($fixture, '08:00:00', 'check_in');
    expect(officeAssess($fixture)->status)->toBe('missing_punch');
    $this->actingAs($fixture['hr']);

    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $manual = app(DayReview::class)->addMissingTime($fixture['employee'], '2026-10-19', '17:00', 'check_out', 'Verified time', $fixture['hr']);
        $day = Day::query()->where('employee_id', $fixture['employee']->id)->whereNull('superseded_by_run_id')->firstOrFail();
        expect($day->status)->toBe('present')->and($day->balance_minutes)->toBe(0);

        app(DayReview::class)->withdraw($manual, 'Incorrect correction', $fixture['hr']);
        expect(Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereNull('superseded_by_run_id')->firstOrFail()->status)->toBe('missing_punch');
    });
});

test('HR confirmation is append-only and another company cannot review the day', function (): void {
    $fixture = officeFixture();
    $other = officeFixture();
    expect(officeAssess($fixture)->status)->toBe('absent');
    $this->actingAs($other['hr']);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $other): void {
        expect(fn () => app(DayReview::class)->confirmAbsence($fixture['employee'], '2026-10-19', 'Checked', $other['hr']))->toThrow(HttpException::class);
    });

    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $decision = app(DayReview::class)->confirmAbsence($fixture['employee'], '2026-10-19', 'Checked', $fixture['hr']);
        expect(Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereNull('superseded_by_run_id')->firstOrFail()->reviewed)->toBeTrue();
        app(DayReview::class)->withdraw($decision, 'Correction', $fixture['hr']);
        expect(Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereNull('superseded_by_run_id')->firstOrFail()->reviewed)->toBeFalse();
    });
});

test('split coverage joins at its boundary and identical reassessment keeps the current row', function (): void {
    $fixture = officeFixture(false);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        foreach ([['00:00:00', '12:00:00'], ['12:00:01', '20:00:00']] as [$from, $to]) {
            CoverageInterval::factory()->create([
                'company_id' => $fixture['company']->id, 'batch_id' => $fixture['batch']->id,
                'branch_id' => $fixture['branch']->id, 'coverage_from' => '2026-10-19 '.$from,
                'coverage_to' => '2026-10-19 '.$to, 'is_confirmed' => true,
            ]);
        }
    });
    $first = officeAssess($fixture);
    $second = officeAssess($fixture);

    expect($first->status)->toBe('absent')
        ->and($second->id)->toBe($first->id);
});

test('back-dated rest days and policy versions supersede only affected dates', function (): void {
    $fixture = officeFixture();
    $first = officeAssess($fixture, '2026-10-19', '2026-10-20');
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $first): void {
        app(EmployeeHistory::class)->record($fixture['employee'], ['rest_days' => [1]], '2026-10-19', 'manual', $fixture['hr']);
        $rest = Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereDate('work_date', '2026-10-19')->whereNull('superseded_by_run_id')->firstOrFail();
        expect($rest->status)->toBe('rest_day')
            ->and($first->fresh()->superseded_by_run_id)->not->toBeNull()
            ->and($rest->changes)->not->toBeEmpty();

        app(OfficePolicy::class)->add($fixture['company'], ['close_grace_minutes' => 60], '2026-10-20', $fixture['hr'], 'Adjusted close');
        expect(Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereDate('work_date', '2026-10-19')->whereNull('superseded_by_run_id')->firstOrFail()->id)->toBe($rest->id);
        $next = Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereDate('work_date', '2026-10-20')->whereNull('superseded_by_run_id')->firstOrFail();
        expect($next->inputs['policy_version'])->toBe(2);
    });
});

test('a confirmed exit removes current days after the last working day', function (): void {
    $fixture = officeFixture();
    $day = officeAssess($fixture);
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $day): void {
        app(ExitReview::class)->start($fixture['employee'], $fixture['hr']);
        app(ExitReview::class)->confirm($fixture['employee']->fresh(), '2026-10-18', $fixture['hr']);
        expect($day->fresh()->superseded_by_run_id)->not->toBeNull()
            ->and(Day::query()->where('employee_id', $fixture['employee']->id)
                ->whereDate('work_date', '2026-10-19')->whereNull('superseded_by_run_id')->exists())->toBeFalse();
    });
});

test('approved check-in outage is grouped and a resolved issue never reopens', function (): void {
    $fixture = officeFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $device = Device::factory()->create(['company_id' => $fixture['company']->id, 'status' => 'approved']);
        DeviceSetting::factory()->create([
            'company_id' => $fixture['company']->id, 'device_id' => $device->id,
            'branch_id' => $fixture['branch']->id, 'effective_from' => '2026-10-18', 'role' => 'check_in',
        ]);
    });
    expect(officeAssess($fixture)->status)->toBe('device_outage');
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $issue = DataIssue::query()->where('type', 'device_outage')->firstOrFail();
        expect(DataIssue::query()->where('type', 'device_outage')->count())->toBe(1);
        app(DataIssues::class)->resolve($issue, $fixture['hr'], 'Reviewed');
        expect(Day::query()->where('employee_id', $fixture['employee']->id)
            ->whereNull('superseded_by_run_id')->firstOrFail()->status)->toBe('absent');
        officeAssess($fixture);
        expect(DataIssue::query()->where('type', 'device_outage')->count())->toBe(1);
    });
});

test('a device approved after the work date cannot create an earlier outage', function (): void {
    $fixture = officeFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $device = Device::factory()->create(['company_id' => $fixture['company']->id, 'status' => 'approved']);
        DeviceSetting::factory()->create([
            'company_id' => $fixture['company']->id, 'device_id' => $device->id,
            'branch_id' => $fixture['branch']->id, 'effective_from' => '2026-10-20', 'role' => 'check_in',
        ]);
    });

    expect(officeAssess($fixture)->status)->toBe('absent');
});

test('a missing office pattern records a reviewable explanation but another pattern has no office day', function (): void {
    $fixture = officeFixture();
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        app(EmployeeHistory::class)->record($fixture['employee'], ['work_pattern' => null], '2026-10-19', 'manual', $fixture['hr']);
    });
    $missing = officeAssess($fixture);
    expect($missing->status)->toBe('pattern_missing')
        ->and($missing->inputs['reason'])->toBe('missing_pattern');

    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        app(EmployeeHistory::class)->record($fixture['employee'], ['work_pattern' => 'shift'], '2026-10-19', 'manual', $fixture['hr']);
    });
    expect(officeAssess($fixture))->toBeNull();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture, $missing): void {
        expect($missing->fresh()->superseded_by_run_id)->not->toBeNull()
            ->and(Day::query()->where('employee_id', $fixture['employee']->id)
                ->whereNull('superseded_by_run_id')->exists())->toBeFalse();
    });
});

test('a visitor punch on the approved check-in device prevents a false outage', function (): void {
    $fixture = officeFixture();
    app(TenantContext::class)->runForCompany($fixture['company'], function () use ($fixture): void {
        $device = Device::factory()->create(['company_id' => $fixture['company']->id, 'status' => 'approved']);
        DeviceSetting::factory()->create([
            'company_id' => $fixture['company']->id, 'device_id' => $device->id,
            'branch_id' => $fixture['branch']->id, 'effective_from' => '2026-10-18', 'role' => 'check_in',
        ]);
        Transaction::factory()->create([
            'company_id' => $fixture['company']->id, 'first_batch_id' => $fixture['batch']->id,
            'device_id' => $device->id, 'serial_number' => $device->serial_number,
            'person_code' => 'VISITOR-1', 'punched_at' => '2026-10-19 08:00:00',
            'punch_date' => '2026-10-19', 'punch_time' => '08:00',
        ]);
    });

    expect(officeAssess($fixture)->status)->toBe('absent');
});

test('processing emits its committed scope', function (): void {
    $fixture = officeFixture();
    Event::fake([AttendanceProcessed::class]);
    app()->call([new ProcessAttendanceJob(
        $fixture['company']->id, [], '2026-10-19 00:00:00', '2026-10-19 23:59:59', 'test',
    ), 'handle']);
    Event::assertDispatched(AttendanceProcessed::class, fn (AttendanceProcessed $event): bool => $event->companyId === $fixture['company']->id);
});

test('coverage changes queue a branch assessment', function (): void {
    $fixture = officeFixture();
    Queue::fake([AssessDaysJob::class]);
    event(new CoverageChanged($fixture['company']->id, [$fixture['branch']->id], '2026-10-19', '2026-10-19'));
    Queue::assertPushed(AssessDaysJob::class, fn (AssessDaysJob $job): bool => $job->scope['branch_ids'] === [$fixture['branch']->id]);
});

test('the hourly scheduler queues days only after their close grace', function (): void {
    $fixture = officeFixture();
    Carbon::setTestNow('2026-10-19 19:59:00');
    expect(officeAssess($fixture)->status)->toBe('pending');
    Queue::fake([AssessDaysJob::class]);
    expect(app(PendingDaysScheduler::class)->dispatch())->toBe(0);
    Carbon::setTestNow('2026-10-19 20:00:00');
    expect(app(PendingDaysScheduler::class)->dispatch())->toBe(1);
    Queue::assertPushed(AssessDaysJob::class);
});

test('the assessment service cannot read another company from the current tenant', function (): void {
    $first = officeFixture();
    $other = officeFixture();

    app(TenantContext::class)->runForCompany($first['company'], function () use ($other): void {
        expect(fn () => app(DayAssessor::class)->run($other['company'], [
            'employee_ids' => [$other['employee']->id], 'from' => '2026-10-19', 'to' => '2026-10-19',
        ], 'test'))->toThrow(HttpException::class);
    });
});
