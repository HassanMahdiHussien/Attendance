<?php

use App\Enums\UserRole;
use App\Exceptions\TenantMismatch;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\Meal;
use App\Models\Attendance\MonthSummary;
use App\Models\Attendance\Person;
use App\Models\Attendance\Policy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Credit;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestDay;
use App\Models\User;
use App\Services\Attendance\AttendanceProcessor;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\DayReview;
use App\Services\Attendance\OfficePolicy;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\MonthSummaries;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-05 12:00:00');
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id,
        'branch_id' => $this->branch->id, 'employee_code' => '90000001',
        'hire_date' => '2020-01-01', 'work_pattern' => 'office']);
    $this->otherEmployee = Employee::factory()->create(['company_id' => $this->company->id,
        'branch_id' => $this->branch->id, 'employee_code' => '90000002',
        'hire_date' => '2020-01-01', 'work_pattern' => 'office']);
    $this->policy = Policy::factory()->create(['company_id' => $this->company->id,
        'effective_from' => '2026-09-01', 'version' => 1]);
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function monthFixture(object $test): void
{
    foreach ([
        ['2026-09-15', 'present', 8, 0, 8],
        ['2026-09-30', 'absent', 0, 0, 0],
        ['2026-10-02', 'present', 0, 2, 0],
        ['2026-10-05', 'present', 30, 0, 0],
        ['2026-10-15', 'holiday_worked', 0, 0, 0],
        ['2026-11-04', 'present', 0, 3, 0],
    ] as [$date, $status, $late, $early, $short]) {
        Day::factory()->create(['company_id' => $test->company->id, 'employee_id' => $test->employee->id,
            'branch_id' => $test->branch->id, 'policy_id' => $test->policy->id,
            'work_date' => $date, 'status' => $status, 'late_minutes' => $late,
            'early_minutes' => $early, 'short_minutes' => $short,
            'presence_minutes' => $status === 'present' ? 480 - $short : 0,
            'inputs' => ['work_pattern' => 'office']]);
    }
    Day::factory()->create(['company_id' => $test->company->id, 'employee_id' => $test->otherEmployee->id,
        'branch_id' => $test->branch->id, 'policy_id' => $test->policy->id,
        'work_date' => '2026-10-03', 'status' => 'present', 'inputs' => ['work_pattern' => 'office']]);
    Meal::factory()->create(['company_id' => $test->company->id, 'employee_id' => $test->employee->id,
        'meal_date' => '2026-10-02', 'person_code' => $test->employee->employee_code, 'repeat_count' => 1]);
    LeaveRequest::factory()->create(['company_id' => $test->company->id,
        'employee_id' => $test->employee->id, 'owner_code' => $test->employee->employee_code,
        'external_id' => 'INVENTED-PENDING', 'status' => 'pending',
        'start_date' => '2026-09-30', 'end_date' => '2026-10-02']);
    $type = LeaveType::factory()->create(['company_id' => $test->company->id,
        'name' => 'Invented leave', 'covers' => true, 'is_mapped' => true]);
    LeaveRequest::factory()->create(['company_id' => $test->company->id,
        'employee_id' => $test->employee->id, 'owner_code' => $test->employee->employee_code,
        'leave_type_id' => $type->id, 'external_id' => 'INVENTED-HALF-DAY',
        'status' => 'approved', 'start_date' => '2026-10-05', 'end_date' => '2026-10-05',
        'duration_days' => '0.5']);
    LeaveRequest::factory()->create(['company_id' => $test->company->id,
        'employee_id' => $test->employee->id, 'owner_code' => $test->employee->employee_code,
        'leave_type_id' => $type->id, 'external_id' => 'INVENTED-SPLIT-LEAVE',
        'status' => 'approved', 'start_date' => '2026-09-30', 'end_date' => '2026-10-01',
        'duration_days' => '1.5']);
    app(Reconciler::class)->run($test->company, [$test->employee->id], 'test');
    $source = RequestDay::query()->whereHas('request', fn ($query) => $query->where('external_id', 'INVENTED-HALF-DAY'))
        ->firstOrFail();
    Credit::factory()->create(['company_id' => $test->company->id, 'employee_id' => $test->employee->id,
        'source_day_id' => $source->id, 'run_id' => $source->run_id,
        'minutes' => 60, 'expires_on' => '2026-10-31']);
    DataIssue::factory()->create(['company_id' => $test->company->id,
        'type' => 'unknown_person', 'subject_type' => 'person', 'subject_key' => $test->employee->employee_code]);
}

function monthFilters(object $test, string $from, string $to, array $extra = []): ReportFilters
{
    return ReportFilters::fromArray($test->company, ['from' => $from, 'to' => $to] + $extra);
}

function buildFixtureMonths(object $test): void
{
    $summaries = app(MonthSummaries::class);
    $summaries->markDirty($test->company->id, [$test->employee->id, $test->otherEmployee->id], '2026-09-01', '2026-11-05');
    MonthSummary::query()->where('is_dirty', true)->get()->each(fn (MonthSummary $row) => $summaries->build($row));
}

test('whole, partial and current months equal the live calculation', function (): void {
    monthFixture($this);
    $summary = app(AttendanceSummary::class);
    $splitDays = RequestDay::query()->whereHas('request', fn ($query) => $query->where('external_id', 'INVENTED-SPLIT-LEAVE'));
    expect((clone $splitDays)->count())->toBe(2)
        ->and((int) $splitDays->sum('minutes'))->toBe(720);
    $beforeExpiry = $summary->totalsLive(monthFilters($this, '2026-10-01', '2026-10-20'));
    $afterExpiry = $summary->totalsLive(monthFilters($this, '2026-10-01', '2026-11-05'));
    expect($beforeExpiry['credits']['available_at_period_end'])
        ->toBeGreaterThanOrEqual($afterExpiry['credits']['available_at_period_end'] + 60);
    $periods = [
        ['2026-09-01', '2026-11-05', []], ['2026-09-15', '2026-11-05', []],
        ['2026-09-01', '2026-10-20', []], ['2026-10-02', '2026-10-15', []],
        ['2026-11-01', '2026-11-05', []],
        ['2026-09-01', '2026-11-05', ['employee_ids' => [$this->employee->id]]],
    ];
    foreach ($periods as [$from, $to, $extra]) {
        $filters = monthFilters($this, $from, $to, $extra);
        expect($summary->totals($filters))->toEqual($summary->totalsLive($filters));
    }
    buildFixtureMonths($this);
    foreach ($periods as [$from, $to, $extra]) {
        $filters = monthFilters($this, $from, $to, $extra);
        expect($summary->totals($filters))->toEqual($summary->totalsLive($filters));
    }
});

test('a dirty or missing month falls back to live and rebuilding is idempotent', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    $summary = app(AttendanceSummary::class);
    $filters = monthFilters($this, '2026-09-01', '2026-11-05');
    $summaries = app(MonthSummaries::class);
    $month = MonthSummary::query()->where('employee_id', $this->employee->id)->whereDate('month', '2026-10-01')->firstOrFail();
    $version = $month->version;
    $summaries->markDirty($this->company->id, [$this->employee->id], '2026-10-02', '2026-10-02');
    expect($month->fresh()->is_dirty)->toBeTrue()
        ->and($month->fresh()->version)->toBe($version + 1)
        ->and($summary->totals($filters))->toEqual($summary->totalsLive($filters));
    $summaries->build($month->fresh());
    expect($month->fresh()->is_dirty)->toBeFalse()
        ->and($summaries->build($month->fresh()))->toBeFalse();
    $month->delete();
    expect($summary->totals($filters))->toEqual($summary->totalsLive($filters))
        ->and(MonthSummary::query()->where('employee_id', $this->employee->id)
            ->whereDate('month', '2026-10-01')->where('is_dirty', true)->exists())->toBeTrue();
});

test('cancelling a leave dirties the employee months and totals stay live until rebuilt', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    $overtime = app(Overtime::class);
    $filters = monthFilters($this, '2026-09-01', '2026-11-05');
    $overtime->totals($filters);
    $actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($actor);
    $request = LeaveRequest::query()->where('external_id', 'INVENTED-HALF-DAY')->firstOrFail();

    app(LeaveActions::class)->cancel($request, 'Invented correction', $actor);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');

    expect(MonthSummary::query()->where('employee_id', $this->employee->id)->where('is_dirty', true)->count())->toBe(3);
    $summary = app(AttendanceSummary::class);
    expect($summary->totals($filters))->toEqual($summary->totalsLive($filters))
        ->and($overtime->totals($filters))->toEqual($overtime->totalsLive($filters));
    MonthSummary::query()->where('employee_id', $this->employee->id)->where('is_dirty', true)->get()
        ->each(fn (MonthSummary $row) => app(MonthSummaries::class)->build($row));
    expect($summary->totals($filters))->toEqual($summary->totalsLive($filters));
});

test('a day review dirties its month in the decision transaction', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    $actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($actor);

    app(DayReview::class)->confirmAbsence($this->employee, '2026-09-30', 'Invented review', $actor);

    expect(MonthSummary::query()->where('employee_id', $this->employee->id)
        ->whereDate('month', '2026-09-01')->firstOrFail()->is_dirty)->toBeTrue();
});

test('reassessment dirties a changed month and keeps cached totals equal to live', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    $filters = monthFilters($this, '2026-09-01', '2026-11-05');
    $overtime = app(Overtime::class);
    $overtime->totals($filters);
    app(DayAssessor::class)->run($this->company, ['employee_ids' => [$this->employee->id],
        'from' => '2026-10-02', 'to' => '2026-10-02'], 'test');

    expect(MonthSummary::query()->where('employee_id', $this->employee->id)
        ->whereDate('month', '2026-10-01')->firstOrFail()->is_dirty)->toBeTrue();
    expect(app(AttendanceSummary::class)->totals($filters))
        ->toEqual(app(AttendanceSummary::class)->totalsLive($filters))
        ->and($overtime->totals($filters))->toEqual($overtime->totalsLive($filters));
});

test('processing a replacement meal dirties its month', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    Person::factory()->create(['company_id' => $this->company->id,
        'person_code' => $this->employee->employee_code, 'employee_id' => $this->employee->id,
        'kind' => 'employee']);

    app(AttendanceProcessor::class)->run($this->company, [$this->employee->employee_code],
        '2026-10-02 00:00:00', '2026-10-02 23:59:59', 'test');

    expect(MonthSummary::query()->where('employee_id', $this->employee->id)
        ->whereDate('month', '2026-10-01')->firstOrFail()->is_dirty)->toBeTrue();
    $filters = monthFilters($this, '2026-09-01', '2026-11-05');
    expect(app(AttendanceSummary::class)->totals($filters))
        ->toEqual(app(AttendanceSummary::class)->totalsLive($filters));
});

test('a new policy version dirties affected months before reassessment', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    $actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($actor);

    app(OfficePolicy::class)->add($this->company, ['minor_gap_tolerance_minutes' => 0],
        '2026-10-01', $actor, 'Invented policy update');

    expect(MonthSummary::query()->where('employee_id', $this->employee->id)
        ->whereDate('month', '2026-10-01')->firstOrFail()->is_dirty)->toBeTrue();
    $filters = monthFilters($this, '2026-09-01', '2026-11-05');
    expect(app(AttendanceSummary::class)->totals($filters))
        ->toEqual(app(AttendanceSummary::class)->totalsLive($filters));
});

test('filtered totals stay live even if a stored month was tampered with', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    $month = MonthSummary::query()->where('employee_id', $this->employee->id)->whereDate('month', '2026-10-01')->firstOrFail();
    $bad = $month->attendance;
    $bad['days']['present'] = 999;
    $month->update(['attendance' => $bad]);
    $filters = monthFilters($this, '2026-10-01', '2026-10-31', ['branch_ids' => [$this->branch->id]]);
    $summary = app(AttendanceSummary::class);
    $readCache = false;
    DB::listen(function ($query) use (&$readCache): void {
        if (str_contains($query->sql, 'attendance_month_summaries')) {
            $readCache = true;
        }
    });

    expect($summary->totals($filters))->toEqual($summary->totalsLive($filters))
        ->and($readCache)->toBeFalse();
});

test('attributable issues keep the same counts for code, owner, request and request day', function (): void {
    monthFixture($this);
    $request = LeaveRequest::query()->where('external_id', 'INVENTED-PENDING')->firstOrFail();
    DataIssue::factory()->create(['company_id' => $this->company->id, 'type' => 'unknown_person',
        'subject_type' => 'employee_code', 'subject_key' => 'owner:'.$this->employee->employee_code]);
    DataIssue::factory()->create(['company_id' => $this->company->id, 'type' => 'leave_unknown_status',
        'subject_type' => 'leave_request', 'subject_key' => 'request:'.$request->id]);
    DataIssue::factory()->create(['company_id' => $this->company->id, 'type' => 'leave_with_attendance_nothing_to_reconcile',
        'subject_type' => 'leave_request_day',
        'subject_key' => 'employee:'.$this->employee->id.':request_day:123|2026-10-02']);
    $filters = monthFilters($this, '2026-09-01', '2026-11-05');
    $summary = app(AttendanceSummary::class);
    $live = $summary->totalsLive($filters);

    expect($live['issues']['attributable']['unknown_person'])->toBe(2)
        ->and($live['issues']['attributable']['leave_unknown_status'])->toBe(1)
        ->and($live['issues']['attributable']['leave_with_attendance_nothing_to_reconcile'])->toBe(1)
        ->and($summary->totals($filters))->toEqual($live);
    buildFixtureMonths($this);
    expect($summary->totals($filters))->toEqual($live);
});

test('one employee report reads only that employee issue and request subjects', function (): void {
    monthFixture($this);
    $foreignRequest = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->otherEmployee->id, 'owner_code' => $this->otherEmployee->employee_code]);
    DataIssue::factory()->create(['company_id' => $this->company->id, 'type' => 'leave_unknown_status',
        'subject_type' => 'leave_request', 'subject_key' => 'request:'.$foreignRequest->id]);
    $filters = monthFilters($this, '2026-09-01', '2026-11-05', ['employee_ids' => [$this->employee->id]]);
    $issues = iterator_to_array(app(AttendanceSummary::class)->perEmployee($filters))[0]['issues']['attributable'];

    expect($issues['unknown_person'])->toBe(1)
        ->and($issues)->not->toHaveKey('leave_unknown_status');
});

test('the build command creates missing months once and leaves completed versions alone', function (): void {
    monthFixture($this);
    Artisan::call('attendance:month-summaries-build', ['--company' => $this->company->id]);
    $count = MonthSummary::query()->count();
    $summaries = app(MonthSummaries::class);
    MonthSummary::query()->where('is_dirty', true)->get()->each(fn (MonthSummary $row) => $summaries->build($row));
    $versions = MonthSummary::query()->orderBy('id')->pluck('version')->all();

    Artisan::call('attendance:month-summaries-build', ['--company' => $this->company->id]);

    expect(MonthSummary::query()->count())->toBe($count)
        ->and(MonthSummary::query()->orderBy('id')->pluck('version')->all())->toBe($versions);
});

test('the scheduled current-month refresh dirties every existing current row', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    $current = MonthSummary::query()->where('month', '2026-11-01')->orderBy('employee_id')->get();
    $before = $current->pluck('version', 'employee_id')->all();

    Artisan::call('attendance:month-summaries-build', [
        '--company' => $this->company->id, '--refresh-current' => true,
    ]);

    foreach ($current as $row) {
        expect($row->fresh()->is_dirty)->toBeTrue()
            ->and($row->fresh()->version)->toBe($before[$row->employee_id] + 1);
    }
});

test('employee chunk boundaries do not change per-employee or total results', function (): void {
    monthFixture($this);
    Employee::factory()->count(101)->create(['company_id' => $this->company->id,
        'branch_id' => $this->branch->id, 'hire_date' => '2020-01-01']);
    $filters = monthFilters($this, '2026-10-01', '2026-10-31');
    $summary = app(AttendanceSummary::class);
    $rows = iterator_to_array($summary->perEmployee($filters));

    expect($rows)->toHaveCount(103)
        ->and($summary->totals($filters))->toEqual($summary->totalsLive($filters));
});

test('a month build does not clear dirtiness when its version changes during calculation', function (): void {
    monthFixture($this);
    $summaries = app(MonthSummaries::class);
    $summaries->markDirty($this->company->id, [$this->employee->id], '2026-10-01', '2026-10-31');
    $month = MonthSummary::query()->where('employee_id', $this->employee->id)
        ->where('month', '2026-10-01')->firstOrFail();
    $initialVersion = (int) $month->version;
    $changed = false;
    DB::listen(function ($query) use ($summaries, &$changed): void {
        if (! $changed && str_contains($query->sql, 'attendance_days')) {
            $changed = true;
            $summaries->markDirty($this->company->id, [$this->employee->id], '2026-10-01', '2026-10-31');
        }
    });

    expect($summaries->build($month))->toBeFalse()
        ->and($month->fresh()->is_dirty)->toBeTrue()
        ->and($month->fresh()->version)->toBe($initialVersion + 1);
});

test('the monthly table is tenant scoped for reads and writes', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    $own = MonthSummary::query()->firstOrFail();
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, function () use ($own, $other): void {
        expect(MonthSummary::query()->whereKey($own->id)->first())->toBeNull();
        expect(fn () => MonthSummary::create(['company_id' => $other->id,
            'employee_id' => $this->employee->id, 'month' => '2026-10-01']))
            ->toThrow(TenantMismatch::class);
    });
});

test('the verify command reports matching fields and detects a tampered snapshot', function (): void {
    monthFixture($this);
    buildFixtureMonths($this);
    Artisan::call('attendance:month-summaries-verify', ['--company' => $this->company->id]);
    expect(Artisan::output())->toContain('0 mismatched');
    $month = MonthSummary::query()->where('employee_id', $this->employee->id)->whereDate('month', '2026-10-01')->firstOrFail();
    $bad = $month->attendance;
    $bad['days']['present'] = 999;
    $month->update(['attendance' => $bad]);
    Artisan::call('attendance:month-summaries-verify', ['--company' => $this->company->id]);
    expect(Artisan::output())->toContain('1 mismatched')->toContain('days');
});
