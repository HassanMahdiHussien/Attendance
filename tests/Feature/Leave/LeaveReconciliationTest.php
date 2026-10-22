<?php

use App\Enums\UserRole;
use App\Events\DaysAssessed;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestDecision;
use App\Models\User;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\OfficePolicy;
use App\Services\Leave\DayReconciliation;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\LeaveSettings;
use App\Services\Leave\LeaveTypes;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->employee = Employee::factory()->create([
        'company_id' => $this->company->id, 'employee_code' => '12345678',
        'hire_date' => '2020-01-01', 'work_pattern' => 'office',
    ]);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($this->actor);
    $this->type = LeaveType::factory()->create(['covers' => true, 'is_mapped' => true]);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function reconciledRequest(Employee $employee, LeaveType $type, string $id, string $date, float $duration = 0.5, string $status = 'approved'): LeaveRequest
{
    return LeaveRequest::factory()->create([
        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
        'owner_code' => $employee->employee_code, 'leave_type_id' => $type->id,
        'external_id' => $id, 'status' => $status, 'start_date' => $date,
        'end_date' => $date, 'duration_days' => $duration,
    ]);
}

function reconciledDay(Employee $employee, string $date, string $status, int $late = 0, int $early = 0): Day
{
    return Day::factory()->create([
        'company_id' => $employee->company_id, 'employee_id' => $employee->id,
        'work_date' => $date, 'status' => $status, 'late_minutes' => $late, 'early_minutes' => $early,
    ]);
}

function replayLeave(Company $company, Employee $employee): void
{
    app(Reconciler::class)->run($company, [$employee->id], 'test');
}

test('same day leave settles a balance and surplus credit pays later lateness', function (): void {
    reconciledRequest($this->employee, $this->type, 'FORM-A', '2026-10-19');
    reconciledDay($this->employee, '2026-10-19', 'present', early: 180);
    reconciledDay($this->employee, '2026-10-20', 'present', late: 15);
    replayLeave($this->company, $this->employee);

    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(2)
        ->and(Credit::query()->whereNull('superseded_by_run_id')->value('minutes'))->toBe(60)
        ->and(app(DayReconciliation::class)->for($this->employee, '2026-10-20')['2026-10-20']['remaining']['late'])->toBe(0);
    $credit = Allocation::query()->whereNotNull('source_credit_id')->firstOrFail();
    expect($credit->explanation['request'])->toBe('FORM-A')->and($credit->minutes)->toBe(15);
});

test('a half day leaves four hours of absence and a full day closes it', function (): void {
    $request = reconciledRequest($this->employee, $this->type, 'FORM-A', '2026-10-19');
    reconciledDay($this->employee, '2026-10-19', 'absent');
    replayLeave($this->company, $this->employee);
    expect(app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19']['remaining']['absence'])->toBe(240);

    app(LeaveActions::class)->fixDates($request, '2026-10-19', '2026-10-19', 1.0, 'Confirmed full day', $this->actor);
    replayLeave($this->company, $this->employee);
    expect(app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19']['absence_closed'])->toBeTrue();
});

test('cancelling a source refills the deduction from another valid credit', function (): void {
    $first = reconciledRequest($this->employee, $this->type, 'FORM-A', '2026-10-18');
    reconciledRequest($this->employee, $this->type, 'FORM-B', '2026-10-19');
    reconciledDay($this->employee, '2026-10-18', 'present');
    reconciledDay($this->employee, '2026-10-19', 'present');
    reconciledDay($this->employee, '2026-10-20', 'present', late: 15);
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->firstOrFail()->explanation['request'])->toBe('FORM-A');

    app(LeaveActions::class)->cancel($first, 'Report corrected', $this->actor);
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->firstOrFail()->explanation['request'])->toBe('FORM-B');
});

test('a reversed credit deduction stays unfunded until the decision is withdrawn', function (): void {
    reconciledRequest($this->employee, $this->type, 'FORM-A', '2026-10-19');
    reconciledDay($this->employee, '2026-10-19', 'present');
    reconciledDay($this->employee, '2026-10-20', 'present', late: 15);
    replayLeave($this->company, $this->employee);
    $decision = app(LeaveActions::class)->reverseDeduction($this->employee, '2026-10-20', 'late', 'Review', $this->actor);
    replayLeave($this->company, $this->employee);
    expect(app(DayReconciliation::class)->for($this->employee, '2026-10-20')['2026-10-20']['remaining']['late'])->toBe(15);

    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0);
    app(LeaveActions::class)->withdraw($decision, 'Review complete', $this->actor);
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(1);
});

test('replay is idempotent and moving an office day removes its old allocation', function (): void {
    reconciledRequest($this->employee, $this->type, 'FORM-A', '2026-10-19', 1.0);
    $day = reconciledDay($this->employee, '2026-10-19', 'absent');
    replayLeave($this->company, $this->employee);
    $id = Allocation::query()->whereNull('superseded_by_run_id')->firstOrFail()->id;
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->firstOrFail()->id)->toBe($id);

    $day->update(['superseded_by_run_id' => $day->run_id]);
    reconciledDay($this->employee, '2026-10-19', 'present');
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0);
});

test('day assessment dispatches a scoped reconciliation job', function (): void {
    event(new DaysAssessed($this->company->id, [$this->employee->id], '2026-10-19', '2026-10-20'));
    Queue::assertPushed(ReconcileLeaveJob::class, fn (ReconcileLeaveJob $job): bool => $job->companyId === $this->company->id && $job->employeeIds === [$this->employee->id]);
});

test('assessing a changed office day emits the affected employee and date', function (): void {
    Event::fake([DaysAssessed::class]);
    app(OfficePolicy::class)->createDefault($this->company);
    app(DayAssessor::class)->run($this->company, [
        'employee_ids' => [$this->employee->id], 'from' => '2026-10-19', 'to' => '2026-10-19',
    ], 'test');
    Event::assertDispatched(DaysAssessed::class, fn (DaysAssessed $event): bool => $event->companyId === $this->company->id && $event->employeeIds === [$this->employee->id]
        && $event->from === '2026-10-19');
});

test('pending and rejected requests create no covering days or credits', function (): void {
    $pending = reconciledRequest($this->employee, $this->type, 'PENDING', '2026-10-19', status: 'pending');
    $pending->update(['approver_name' => 'Fake Approver']);
    reconciledRequest($this->employee, $this->type, 'REJECTED', '2026-10-20', status: 'rejected');
    reconciledDay($this->employee, '2026-10-19', 'absent');
    reconciledDay($this->employee, '2026-10-20', 'absent');
    replayLeave($this->company, $this->employee);
    expect(RequestDay::query()->whereNull('superseded_by_run_id')->count())->toBe(0)
        ->and(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0)
        ->and(app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19']['pending_requests'][0]['approver_name'])
        ->toBe('Fake Approver');
});

test('approved leave with full attendance raises a review issue without an allocation', function (): void {
    reconciledRequest($this->employee, $this->type, 'FORM-A', '2026-10-19');
    reconciledDay($this->employee, '2026-10-19', 'present');
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0)
        ->and(DataIssue::query()->where('type', 'leave_with_attendance_nothing_to_reconcile')->where('status', 'open')->count())->toBe(1);
});

test('mismatched duration waits for HR confirmation', function (): void {
    $request = LeaveRequest::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'leave_type_id' => $this->type->id,
        'external_id' => 'MISMATCH', 'status' => 'approved', 'start_date' => '2026-10-18',
        'end_date' => '2026-10-20', 'duration_days' => 1.0,
    ]);
    replayLeave($this->company, $this->employee);
    expect(RequestDay::query()->whereNull('superseded_by_run_id')->count())->toBe(0);
    app(LeaveActions::class)->confirmDistribution($request, 'from_start', null, 'One day confirmed', $this->actor);
    replayLeave($this->company, $this->employee);
    expect(RequestDay::query()->whereNull('superseded_by_run_id')->count())->toBe(1);
});

test('mapping an unmapped type makes approved leave eligible', function (): void {
    $unmapped = LeaveType::factory()->create(['covers' => false, 'is_mapped' => false]);
    reconciledRequest($this->employee, $unmapped, 'FORM-A', '2026-10-19', 1.0);
    reconciledDay($this->employee, '2026-10-19', 'absent');
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0);

    app(LeaveTypes::class)->map($unmapped, true, 'none', 'HR confirmed coverage', $this->actor);
    Queue::assertPushed(ReconcileLeaveJob::class);
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(1);
});

test('changing credit expiry replays the employee and stops an expired deduction', function (): void {
    reconciledRequest($this->employee, $this->type, 'FORM-A', '2026-10-19');
    reconciledDay($this->employee, '2026-10-19', 'present');
    reconciledDay($this->employee, '2026-10-20', 'present', late: 15);
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(1);

    app(LeaveSettings::class)->setCreditExpiry($this->company, 0, 'Credit policy changed', $this->actor);
    Queue::assertPushed(ReconcileLeaveJob::class);
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0);
});

test('a two and a half day request distributes across working days and skips Friday', function (): void {
    LeaveRequest::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'leave_type_id' => $this->type->id,
        'external_id' => 'MULTI', 'status' => 'approved', 'start_date' => '2026-10-21',
        'end_date' => '2026-10-25', 'duration_days' => 2.5,
    ]);
    replayLeave($this->company, $this->employee);
    $days = RequestDay::query()->whereNull('superseded_by_run_id')->orderBy('work_date')->get();
    expect($days->pluck('minutes')->all())->toBe([480, 480, 240])
        ->and($days->map(fn (RequestDay $day): string => $day->work_date->toDateString())->all())
        ->toBe(['2026-10-21', '2026-10-22', '2026-10-25']);
});

test('reconciliation cannot read or write another company records', function (): void {
    $other = Company::factory()->create();
    $foreign = app(TenantContext::class)->runForCompany($other, fn (): Employee => Employee::factory()->create([
        'company_id' => $other->id, 'hire_date' => '2020-01-01', 'work_pattern' => 'office',
    ]));
    app(TenantContext::class)->setCompany($this->company);
    app(Reconciler::class)->run($this->company, [$foreign->id], 'test');
    expect(fn () => app(DayReconciliation::class)->for($foreign, '2026-10-19'))->toThrow(HttpException::class)
        ->and(RequestDay::query()->where('employee_id', $foreign->id)->count())->toBe(0);
});

test('leave decisions require an active HR or platform user in the selected company', function (): void {
    $request = reconciledRequest($this->employee, $this->type, 'FORM-A', '2026-10-19');
    $other = Company::factory()->create();
    $foreignHr = app(TenantContext::class)->runForCompany($other, fn (): User => User::factory()->create([
        'company_id' => $other->id, 'primary_role' => UserRole::HrStaff,
    ]));
    app(TenantContext::class)->setCompany($this->company);
    $this->actingAs($foreignHr);
    // Another company's HR is refused by the gate before anything changes.
    expect(fn () => app(LeaveActions::class)->cancel($request, 'Incorrect request', $foreignHr))
        ->toThrow(AuthorizationException::class)
        ->and(RequestDecision::query()->withoutGlobalScopes()->where('request_id', $request->id)->exists())->toBeFalse();
});
