<?php

use App\Enums\UserRole;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Models\Attendance\Day;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\Policy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\ProcessingRun;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestDecision;
use App\Models\User;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\DayPresentation;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Leave\DayReconciliation;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id,
        'branch_id' => $this->branch->id, 'employee_code' => '00001234', 'hire_date' => '2020-01-01', 'work_pattern' => 'office']);
    $this->type = LeaveType::factory()->create(['company_id' => $this->company->id,
        'name' => 'Invented leave', 'covers' => true, 'is_mapped' => true]);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->policy = Policy::factory()->create(['company_id' => $this->company->id,
        'effective_from' => '2026-10-01', 'version' => 1]);
    $this->actingAs($this->actor);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function settlementDay(object $test, int $late, int $early, int $short, int $presence, string $date = '2026-10-19'): Day
{
    return Day::factory()->create(['company_id' => $test->company->id, 'employee_id' => $test->employee->id,
        'branch_id' => $test->branch->id, 'policy_id' => $test->policy->id, 'work_date' => $date, 'status' => 'present',
        'late_minutes' => $late, 'early_minutes' => $early, 'short_minutes' => $short,
        'presence_minutes' => $presence, 'balance_minutes' => $late + $early]);
}

function settlementLeave(object $test, string $date = '2026-10-19', float $duration = 0.5): LeaveRequest
{
    return LeaveRequest::factory()->create(['company_id' => $test->company->id,
        'employee_id' => $test->employee->id, 'owner_code' => $test->employee->employee_code,
        'leave_type_id' => $test->type->id, 'external_id' => 'INVENTED-FORM', 'status' => 'approved',
        'start_date' => $date, 'end_date' => $date, 'duration_days' => $duration]);
}

function settlementRows(object $test): array
{
    $filters = ReportFilters::fromArray($test->company, ['from' => '2026-10-18', 'to' => '2026-10-21',
        'employee_ids' => [$test->employee->id]]);
    $summary = app(AttendanceSummary::class);

    return [collect(iterator_to_array($summary->perDay($filters, $test->employee->id)))->firstWhere('work_date', '2026-10-19'), $summary->totals($filters)];
}

test('a half-day leave and worked hours settle the office example without another allocation', function (): void {
    settlementDay($this, 210, 53, 203, 277);
    $request = settlementLeave($this);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');

    $allocations = Allocation::query()->whereNull('superseded_by_run_id')->orderBy('id')->get();
    [$day, $totals] = settlementRows($this);
    $reconciliation = app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19'];
    $presentation = app(DayPresentation::class)->from($day);

    expect($allocations->pluck('minutes')->all())->toBe([210, 30])
        ->and($day['minutes']['late']['remaining'])->toBe(0)
        ->and($day['minutes']['early']['remaining'])->toBe(0)
        ->and($day['minutes']['early']['settled_by_hours'])->toBe(23)
        ->and($day['short_remaining'])->toBe(0)
        ->and($day['flags']['short_hours'])->toBeFalse()
        ->and($reconciliation['remaining']['early'])->toBe(0)
        ->and($reconciliation['settled_by_hours']['early'])->toBe(23)
        ->and($totals['minutes']['early']['remaining'])->toBe(0)
        ->and($totals['minutes']['early']['settled_by_hours'])->toBe(23)
        ->and($totals['short_hours_alerts'])->toBe(0)
        ->and($presentation['status_key'])->toBe('covered')
        ->and($presentation['needs_review'])->toBeFalse()
        ->and($presentation['problems'])->toContain('Covered by leave 4 h')
        ->and($presentation['problems'])->not->toContain('Short hours 3 h 23 min')
        ->and(FollowUpCase::query()->where('case_kind', 'day')->where('state', '!=', 'closed')->count())->toBe(0);

    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    expect(Allocation::query()->whereNull('superseded_by_run_id')->orderBy('id')->pluck('id')->all())
        ->toBe($allocations->pluck('id')->all());

    app(LeaveActions::class)->cancel($request, 'Invented correction', $this->actor);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$reverted] = settlementRows($this);
    expect($reverted['minutes']['late']['remaining'])->toBe(210)
        ->and($reverted['minutes']['early']['remaining'])->toBe(53)
        ->and($reverted['short_remaining'])->toBe(203)
        ->and(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0);
});

test('the short-hours boundary controls settlement and leaves an uncovered minute visible', function (int $short, int $expectedRemaining, string $status): void {
    settlementDay($this, 0, 263, $short, 480 - $short);
    settlementLeave($this);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day] = settlementRows($this);
    $presentation = app(DayPresentation::class)->from($day);

    expect($day['minutes']['early']['remaining'])->toBe($expectedRemaining)
        ->and($day['short_remaining'])->toBe(max(0, $short - 240))
        ->and($presentation['status_key'])->toBe($status)
        ->and($presentation['problems'])->toContain($status === 'covered' ? 'Covered by leave 4 h' : 'Leave 4 h');
})->with([[240, 0, 'covered'], [241, 23, 'short']]);

test('a no-leave early departure and a no-leave short day remain open', function (int $early, int $short): void {
    settlementDay($this, 0, $early, $short, $short === 0 ? 538 : 277);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day] = settlementRows($this);
    expect($day['minutes']['early']['remaining'])->toBe($early)
        ->and($day['minutes']['early']['settled_by_hours'])->toBe(0)
        ->and($day['short_remaining'])->toBe($short)
        ->and(app(DayPresentation::class)->from($day)['needs_review'])->toBeTrue();
})->with([[6, 0], [53, 203]]);

test('credit alone can cover the balance but cannot trigger worked-hours settlement', function (): void {
    settlementDay($this, 0, 0, 0, 480, '2026-10-18');
    settlementDay($this, 0, 30, 30, 450);
    settlementLeave($this, '2026-10-18');
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day] = settlementRows($this);
    expect($day['minutes']['early']['reconciled_by_credit'])->toBe(30)
        ->and($day['minutes']['early']['settled_by_hours'])->toBe(0)
        ->and($day['minutes']['early']['remaining'])->toBe(0)
        ->and($day['short_remaining'])->toBe(0);
});

test('the leave coverage and worked-hours labels exist in English and Arabic', function (): void {
    foreach (['en', 'ar'] as $locale) {
        expect(Lang::has('messages.day_presentation.covered_by_leave', $locale))->toBeTrue()
            ->and(Lang::has('messages.day_presentation.leave_applied', $locale))->toBeTrue()
            ->and(Lang::has('messages.clear_screens.settled_by_hours', $locale))->toBeTrue();
    }
});

test('a two-minute full-hours gap is within tolerance in every projection', function (): void {
    settlementDay($this, 0, 2, 0, 538);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day, $totals] = settlementRows($this);
    $reconciliation = app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19'];
    $presentation = app(DayPresentation::class)->from($day);

    expect($day['minutes']['early']['remaining'])->toBe(0)
        ->and($day['minutes']['early']['settled_by_hours'])->toBe(2)
        ->and($day['flags']['within_tolerance'])->toBeTrue()
        ->and($day['minor_gap_tolerance'])->toBe(5)
        ->and($reconciliation['remaining']['early'])->toBe(0)
        ->and($reconciliation['settled_by_hours']['early'])->toBe(2)
        ->and($totals['minutes']['early']['remaining'])->toBe(0)
        ->and($totals['minutes']['early']['settled_by_hours'])->toBe(2)
        ->and($totals['flags']['within_tolerance'])->toBe(1)
        ->and($presentation['status_key'])->toBe('present')
        ->and($presentation['tone'])->toBe('green')
        ->and($presentation['needs_review'])->toBeFalse()
        ->and($presentation['early'])->toBeFalse()
        ->and($presentation['problems'])->toBe(['Within the allowed 5 minutes'])
        ->and(FollowUpCase::query()->where('case_kind', 'day')->where('state', '!=', 'closed')->count())->toBe(0);
});

test('the employee review filter leaves out a day within tolerance', function (): void {
    settlementDay($this, 0, 2, 0, 538);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');

    Livewire::test(EmployeeAttendance::class, ['employeeId' => $this->employee->id])
        ->set('from', '2026-10-19')->set('to', '2026-10-19')
        ->assertDontSee('Within the allowed 5 minutes')
        ->call('setDayFilter', 'all')->assertSee('Within the allowed 5 minutes');
});

test('credit skips a tolerable day and reaches the next unreconciled day', function (): void {
    settlementDay($this, 0, 0, 0, 540, '2026-10-18');
    settlementDay($this, 0, 2, 0, 538);
    settlementDay($this, 0, 20, 20, 520, '2026-10-20');
    settlementLeave($this, '2026-10-18');

    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    $allocations = Allocation::query()->whereNull('superseded_by_run_id')->get();
    [$day] = settlementRows($this);

    expect($day['minutes']['early']['settled_by_hours'])->toBe(2)
        ->and($allocations->where('target_day_id', $day['day_id']))->toHaveCount(0)
        ->and((int) $allocations->where('target_kind', 'early')->sum('minutes'))->toBe(20);

    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    expect(Allocation::query()->whereNull('superseded_by_run_id')->pluck('id')->all())
        ->toBe($allocations->pluck('id')->all());
});

test('reads remain nonnegative before an old credit allocation is replayed', function (): void {
    $day = settlementDay($this, 0, 2, 0, 538);
    $request = settlementLeave($this, '2026-10-18');
    $run = ProcessingRun::factory()->create(['company_id' => $this->company->id]);
    $source = RequestDay::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'request_id' => $request->id,
        'run_id' => $run->id, 'work_date' => '2026-10-18', 'minutes' => 240]);
    $credit = Credit::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'source_day_id' => $source->id,
        'run_id' => $run->id, 'minutes' => 240]);
    Allocation::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'source_request_day_id' => null,
        'source_credit_id' => $credit->id, 'target_day_id' => $day->id,
        'target_kind' => 'early', 'minutes' => 2, 'run_id' => $run->id,
        'explanation' => ['code' => 'leave_credit_applied']]);

    [$projected] = settlementRows($this);
    $reconciliation = app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19'];

    expect($projected['flags']['within_tolerance'])->toBeTrue()
        ->and($projected['minutes']['early']['remaining'])->toBe(0)
        ->and($projected['minutes']['early']['settled_by_hours'])->toBe(0)
        ->and($reconciliation['remaining']['early'])->toBe(0);
});

test('the tolerance is all or nothing and requires a full-hours present day', function (int $late, int $early, int $short, int $remaining): void {
    settlementDay($this, $late, $early, $short, 540 - $short);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day] = settlementRows($this);
    $presentation = app(DayPresentation::class)->from($day);

    expect($day['minutes']['late']['remaining'] + $day['minutes']['early']['remaining'])->toBe($remaining)
        ->and($day['flags']['within_tolerance'])->toBe($remaining === 0)
        ->and($presentation['needs_review'])->toBe($remaining > 0);
})->with([[0, 5, 0, 0], [0, 6, 0, 6], [3, 3, 0, 6], [2, 3, 0, 0], [0, 2, 1, 2]]);

test('counted exits share the same five-minute allowance', function (): void {
    $this->policy = Policy::factory()->create(['company_id' => $this->company->id,
        'effective_from' => '2026-10-19', 'version' => 2, 'exits_count_toward_balance' => true]);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id, 'policy_id' => $this->policy->id,
        'work_date' => '2026-10-19', 'status' => 'present', 'short_minutes' => 0,
        'early_minutes' => 2, 'exit_minutes' => 3, 'presence_minutes' => 538]);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day] = settlementRows($this);
    $reconciliation = app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19'];

    expect($day['minutes']['early']['settled_by_hours'])->toBe(2)
        ->and($day['minutes']['exit']['settled_by_hours'])->toBe(3)
        ->and($reconciliation['remaining']['early'])->toBe(0)
        ->and($reconciliation['remaining']['exit'])->toBe(0);
});

test('a zero-tolerance policy version leaves a two-minute gap open', function (): void {
    $newPolicy = Policy::factory()->create(['company_id' => $this->company->id,
        'effective_from' => '2026-10-19', 'version' => 2, 'minor_gap_tolerance_minutes' => 0]);
    $this->policy = $newPolicy;
    settlementDay($this, 0, 2, 0, 538);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day] = settlementRows($this);

    expect($day['minutes']['early']['remaining'])->toBe(2)
        ->and($day['flags']['within_tolerance'])->toBeFalse()
        ->and(app(DayPresentation::class)->from($day)['needs_review'])->toBeTrue();
});

test('an existing follow-up case closes after a tolerable day is replayed', function (): void {
    $day = settlementDay($this, 0, 2, 0, 538);
    $case = FollowUpCase::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'day_id' => $day->id,
        'case_kind' => 'day', 'work_date' => '2026-10-19', 'state' => 'open',
        'items' => [['type' => 'early', 'minutes' => 2]]]);

    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');

    expect($case->fresh()->state)->toBe('closed')
        ->and($case->fresh()->items)->toBe([]);
});

test('a reversed kind stays open while another kind can be within tolerance', function (): void {
    settlementDay($this, 3, 2, 0, 540);
    $request = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'owner_code' => $this->employee->employee_code,
        'leave_type_id' => $this->type->id, 'external_id' => 'INVENTED-PENDING',
        'status' => 'pending', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19']);
    RequestDecision::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'request_id' => $request->id,
        'decision' => 'reverse_deduction', 'work_date' => '2026-10-19',
        'target_kind' => 'late', 'created_by' => $this->actor->id]);

    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day] = settlementRows($this);
    $reconciliation = app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19'];

    expect($day['minutes']['late']['remaining'])->toBe(3)
        ->and($day['minutes']['early']['remaining'])->toBe(0)
        ->and($day['flags']['within_tolerance'])->toBeTrue()
        ->and($reconciliation['remaining']['late'])->toBe(3)
        ->and($reconciliation['settled_by_hours']['early'])->toBe(2)
        ->and(app(DayPresentation::class)->from($day)['needs_review'])->toBeTrue();
});

test('an absent day never receives minor gap tolerance', function (): void {
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id, 'policy_id' => $this->policy->id,
        'work_date' => '2026-10-19', 'status' => 'absent', 'short_minutes' => 0]);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    [$day] = settlementRows($this);

    expect($day['flags']['within_tolerance'])->toBeFalse()
        ->and($day['minutes']['absence']['remaining'])->toBeGreaterThan(0);
});

test('tolerance and its policy field have English and Arabic labels', function (): void {
    foreach (['en', 'ar'] as $locale) {
        expect(Lang::has('messages.day_presentation.within_tolerance', $locale))->toBeTrue()
            ->and(Lang::has('messages.office_ui.policy_fields.minor_gap_tolerance_minutes', $locale))->toBeTrue()
            ->and(Lang::has('messages.office_ui.minor_gap_tolerance_help', $locale))->toBeTrue();
    }
});
