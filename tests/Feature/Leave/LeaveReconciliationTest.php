<?php

use App\Enums\UserRole;
use App\Events\DaysAssessed;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\PendingLeaveReconciliation;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\DescriptionReading;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestDecision;
use App\Models\Leave\RequestVersion;
use App\Models\User;
use App\Services\Attendance\Ai\ChatTools;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\OfficePolicy;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Leave\DayReconciliation;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\LeaveDescriptionReader;
use App\Services\Leave\LeaveSettings;
use App\Services\Leave\LeaveTypes;
use App\Services\Leave\PendingReconciliation;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
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

test('proposed hourly period takes older minutes first and rejection and cancellation replay cleanly', function (): void {
    $this->type->update(['name' => 'Overtime Equivelant Leave']);
    $request = reconciledRequest($this->employee, $this->type, 'INVENTED-HOURS', '2026-10-19');
    $batch = ImportBatch::factory()->create(['company_id' => $this->company->id]);
    $version = RequestVersion::factory()->create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'batch_id' => $batch->id, 'row_number' => 2,
        'values' => ['description' => 'hourly leave during July 2026 and August 2026'], 'raw_values' => []]);
    $request->update(['current_version_id' => $version->id]);
    $reading = DescriptionReading::create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'text_hash' => hash('sha256', 'hourly leave during July 2026 and August 2026'),
        'category' => 'hourly_settlement', 'worked_dates' => [],
        'settled_from' => '2026-07-01', 'settled_to' => '2026-08-31',
        'confidence' => 'high', 'status' => 'proposed']);
    $july = reconciledDay($this->employee, '2026-07-04', 'present', late: 120);
    reconciledDay($this->employee, '2026-08-05', 'present', early: 60);
    reconciledDay($this->employee, '2026-10-19', 'present', early: 180);
    replayLeave($this->company, $this->employee);
    $before = (int) Allocation::query()->whereNull('superseded_by_run_id')->where('target_day_id', $july->id)->sum('minutes');
    expect($before)->toBe(120);
    $reading->update(['status' => 'confirmed']);
    replayLeave($this->company, $this->employee);
    expect((int) Allocation::query()->whereNull('superseded_by_run_id')->where('target_day_id', $july->id)->sum('minutes'))->toBe(120)
        ->and(Allocation::query()->whereNull('superseded_by_run_id')->where('explanation->code', 'leave_description_period')->count())->toBe(2);
    $filterInput = ['from' => '2026-07-04', 'to' => '2026-10-19', 'employee_ids' => [$this->employee->id]];
    $filters = ReportFilters::fromArray($this->company, $filterInput);
    $profileDay = collect(iterator_to_array(app(AttendanceSummary::class)->perDay($filters, $this->employee->id)))
        ->firstWhere('work_date', '2026-07-04');
    $dashboard = app(AttendanceSummary::class)->totals($filters);
    $chat = app(ChatTools::class)->execute('day_details',
        ['employee_ids' => [$this->employee->id], 'filters' => $filterInput], $this->company, $this->actor);
    $chatDay = collect($chat['data'])->pluck('day')->firstWhere('work_date', '2026-07-04');
    $export = AttendanceExport::factory()->create(['company_id' => $this->company->id,
        'kind' => 'lateness_reconciliation', 'filters' => $filterInput]);
    $reportDay = collect(iterator_to_array(app(ExportData::class)->rows($export, $filters)))
        ->firstWhere('date', '2026-07-04');
    expect($profileDay['minutes']['late']['reconciled_by_leave'])->toBe(120)
        ->and($chatDay['minutes']['late'])->toBe($profileDay['minutes']['late'])
        ->and($reportDay['leave'])->toBe(120)
        ->and($dashboard['minutes']['late']['reconciled_by_leave'])->toBe(120);
    $active = Allocation::query()->whereNull('superseded_by_run_id')->pluck('id')->all();
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->pluck('id')->all())->toBe($active);
    $reading->update(['status' => 'rejected']);
    replayLeave($this->company, $this->employee);
    expect((int) Allocation::query()->whereNull('superseded_by_run_id')->where('target_day_id', $july->id)->sum('minutes'))->toBe(60);
    app(LeaveActions::class)->cancel($request, 'Invented correction', $this->actor);
    replayLeave($this->company, $this->employee);
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0);
});

test('hours last week cover Sunday early leave before January absence', function (): void {
    $this->type->update(['name' => 'Annual Leave']);
    $request = reconciledRequest($this->employee, $this->type, 'FORM-WEEK', '2026-11-02');
    $batch = ImportBatch::factory()->create(['company_id' => $this->company->id]);
    $version = RequestVersion::factory()->create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'batch_id' => $batch->id, 'row_number' => 2,
        'values' => ['description' => 'Hours last week'], 'raw_values' => []]);
    $request->update(['current_version_id' => $version->id]);
    $period = app(LeaveDescriptionReader::class)->hourlyPeriod('Hours last week', '2026-11-02', 'high');
    DescriptionReading::create(['company_id' => $this->company->id, 'request_id' => $request->id,
        'text_hash' => hash('sha256', 'Hours last week'), 'category' => 'hourly_settlement',
        'worked_dates' => [], 'status' => 'proposed'] + $period);
    $old = reconciledDay($this->employee, '2026-01-05', 'absent');
    $sunday = reconciledDay($this->employee, '2026-11-01', 'present', early: 72);
    reconciledDay($this->employee, '2026-11-02', 'present');
    replayLeave($this->company, $this->employee);

    $periodAllocation = Allocation::query()->whereNull('superseded_by_run_id')
        ->where('target_day_id', $sunday->id)->sole();
    $creditAllocation = Allocation::query()->whereNull('superseded_by_run_id')
        ->where('target_day_id', $old->id)->firstOrFail();
    expect($periodAllocation->minutes)->toBe(72)
        ->and($periodAllocation->explanation['code'])->toBe('leave_description_period')
        ->and($creditAllocation->minutes)->toBe(168)
        ->and($creditAllocation->source_credit_id)->not->toBeNull();
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

test('day assessment collects a scoped reconciliation job', function (): void {
    config(['queue.default' => 'database']);
    event(new DaysAssessed($this->company->id, [$this->employee->id], '2026-10-19', '2026-10-20'));
    Queue::assertPushed(ReconcileLeaveJob::class, fn (ReconcileLeaveJob $job): bool => $job->companyId === $this->company->id && $job->employeeIds === [] && $job->trigger === 'days');
    expect(PendingLeaveReconciliation::query()->where('company_id', $this->company->id)->where('employee_id', $this->employee->id)->exists())->toBeTrue();

    event(new DaysAssessed($this->company->id, [$this->employee->id], '2026-10-15', '2026-10-17'));
    $pending = PendingLeaveReconciliation::query()->where('company_id', $this->company->id)->sole();
    expect((string) $pending->getRawOriginal('from_date'))->toBe('2026-10-15')
        ->and((string) $pending->getRawOriginal('to_date'))->toBe('2026-10-20')
        ->and((int) $pending->revision)->toBe(1);
});

test('without a delaying queue an assessment replays leave straight away as before', function (): void {
    event(new DaysAssessed($this->company->id, [$this->employee->id], '2026-10-19', '2026-10-20'));
    Queue::assertPushed(ReconcileLeaveJob::class, fn (ReconcileLeaveJob $job): bool => $job->employeeIds === [$this->employee->id]);
    expect(PendingLeaveReconciliation::query()->count())->toBe(0);
});

test('coalesced replay preserves allocations and cases from immediate replay', function (): void {
    config(['queue.default' => 'database']);
    reconciledRequest($this->employee, $this->type, 'FORM-COALESCED', '2026-10-19');
    reconciledDay($this->employee, '2026-10-19', 'present', early: 180);
    replayLeave($this->company, $this->employee);
    $allocations = Allocation::query()->whereNull('superseded_by_run_id')->orderBy('id')
        ->get()->map(fn ($row): array => Arr::only($row->getAttributes(), ['source_request_day_id', 'target_day_id', 'minutes']))->all();
    $cases = FollowUpCase::query()->where('employee_id', $this->employee->id)
        ->orderBy('id')->get()->map(fn ($row): array => Arr::only($row->getAttributes(), ['case_kind', 'status', 'work_date']))->all();
    event(new DaysAssessed($this->company->id, [$this->employee->id], '2026-10-19', '2026-10-19'));
    event(new DaysAssessed($this->company->id, [$this->employee->id], '2026-10-18', '2026-10-20'));
    app(PendingReconciliation::class)->run($this->company,
        app(Reconciler::class), new ReconcileLeaveJob($this->company->id, [], 'days'));
    expect(Allocation::query()->whereNull('superseded_by_run_id')->orderBy('id')
        ->get()->map(fn ($row): array => Arr::only($row->getAttributes(), ['source_request_day_id', 'target_day_id', 'minutes']))->all())->toEqual($allocations)
        ->and(FollowUpCase::query()->where('employee_id', $this->employee->id)
            ->orderBy('id')->get()->map(fn ($row): array => Arr::only($row->getAttributes(), ['case_kind', 'status', 'work_date']))->all())->toEqual($cases);
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

test('changing the leave calendar re-distributes a source-counted Saturday without putting minutes on it', function (): void {
    $request = LeaveRequest::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'leave_type_id' => $this->type->id,
        'external_id' => 'SATURDAY-COUNTED', 'status' => 'approved',
        'start_date' => '2026-10-16', 'end_date' => '2026-10-21', 'duration_days' => 5.0,
    ]);
    replayLeave($this->company, $this->employee);
    expect($request->fresh()->counting_method)->toBe('leave_calendar')
        ->and(RequestDay::query()->whereNull('superseded_by_run_id')->orderBy('work_date')->get()
            ->map(fn (RequestDay $day): string => $day->work_date->toDateString())->all())
        ->toBe(['2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21']);

    app(LeaveSettings::class)->setLeaveCalendarRestDays($this->company, [5, 6], 'Company calendar changed', $this->actor);
    Queue::assertPushed(ReconcileLeaveJob::class);
    replayLeave($this->company, $this->employee);
    expect($request->fresh()->counting_method)->toBeNull()
        ->and(RequestDay::query()->whereNull('superseded_by_run_id')->count())->toBe(0)
        ->and(DataIssue::query()->where('type', 'leave_duration_mismatch')->where('status', 'open')->count())->toBe(1);
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
