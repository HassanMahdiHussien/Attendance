<?php

use App\Enums\UserRole;
use App\Models\Attendance\Day;
use App\Models\Attendance\FollowUpCase;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
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
    $this->actingAs($this->actor);
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function settlementDay(object $test, int $late, int $early, int $short, int $presence, string $date = '2026-10-19'): Day
{
    return Day::factory()->create(['company_id' => $test->company->id, 'employee_id' => $test->employee->id,
        'branch_id' => $test->branch->id, 'work_date' => $date, 'status' => 'present',
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
})->with([[2, 0], [53, 203]]);

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
