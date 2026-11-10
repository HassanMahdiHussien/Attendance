<?php

use App\Enums\UserRole;
use App\Events\DaysAssessed;
use App\Jobs\Attendance\AssessDaysJob;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\Day;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidayVersion;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\PendingLeaveReconciliation;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestDay;
use App\Models\User;
use App\Services\Attendance\DayAssessor;
use App\Services\Attendance\HolidayCalendar;
use App\Services\Attendance\Holidays;
use App\Services\Attendance\OfficePolicy;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Services\Employees\EmployeeHistory;
use App\Services\Leave\LeaveDistributor;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-24 12:00:00');
    Queue::fake();
    $this->company = Company::factory()->create(['created_at' => '2020-01-01 00:00:00']);
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create();
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($this->actor);
    app(OfficePolicy::class)->createDefault($this->company);
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

function holidayEmployee(Company $company, Branch $branch, array $attributes = []): Employee
{
    return Employee::factory()->create($attributes + [
        'company_id' => $company->id, 'branch_id' => $branch->id,
        'hire_date' => '2020-01-01', 'work_pattern' => 'office',
    ]);
}

function holidayDraft(Company $company, User $actor, array $attributes = []): Holiday
{
    return app(Holidays::class)->create($company, $attributes + [
        'name_en' => 'Founders Day', 'ranges' => [['from' => '2026-10-19', 'to' => '2026-10-19']],
    ], $actor);
}

function holidayAssess(Company $company, Employee $employee, string $date = '2026-10-19'): Day
{
    app(DayAssessor::class)->run($company, [
        'employee_ids' => [$employee->id], 'from' => $date, 'to' => $date,
    ], 'holiday-test');

    return Day::query()->where('employee_id', $employee->id)->whereDate('work_date', $date)
        ->whereNull('superseded_by_run_id')->firstOrFail();
}

test('only approved holidays change days and approval is versioned', function (): void {
    $employee = holidayEmployee($this->company, $this->branch);
    $holiday = holidayDraft($this->company, $this->actor);
    expect(holidayAssess($this->company, $employee)->status)->toBe('pending');

    $approved = app(Holidays::class)->approve($holiday, [], $this->actor, 'Published by HR');
    $day = holidayAssess($this->company, $employee);
    expect($approved->version)->toBe(2)
        ->and($day->status)->toBe('holiday')
        ->and($day->holiday_ids)->toBe([$holiday->id])
        ->and($day->late_minutes)->toBe(0)
        ->and($day->balance_minutes)->toBe(0);

    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-19', 'to' => '2026-10-19']);
    $totals = app(AttendanceSummary::class)->totals($filters);
    expect($totals['days']['holiday'])->toBe(1)
        ->and($totals['assessed_working_days'])->toBe(0);
    Queue::assertPushed(AssessDaysJob::class);
    Queue::assertNotPushed(ReconcileLeaveJob::class,
        fn (ReconcileLeaveJob $job): bool => $job->trigger === 'holiday');
});

test('field work covers only selected people and gives no holiday or overtime credit', function (): void {
    $selected = holidayEmployee($this->company, $this->branch, ['department' => 'Events']);
    $other = holidayEmployee($this->company, $this->branch, ['department' => 'Accounts']);
    $holiday = holidayDraft($this->company, $this->actor, [
        'kind' => 'field_work', 'departments' => [' events '],
    ]);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Off-site work');

    $fieldDay = holidayAssess($this->company, $selected);
    $otherDay = holidayAssess($this->company, $other);
    expect($fieldDay->status)->toBe('field_work')
        ->and($fieldDay->field_work_holiday_id)->toBe($holiday->id)
        ->and($fieldDay->holiday_ids)->toBeNull()
        ->and($fieldDay->late_minutes)->toBe(0)
        ->and($fieldDay->early_minutes)->toBe(0)
        ->and($fieldDay->short_minutes)->toBe(0)
        ->and($fieldDay->overtime_minutes)->toBeNull()
        ->and($otherDay->status)->toBe('pending');

    $totals = app(AttendanceSummary::class)->totals(ReportFilters::fromArray($this->company,
        ['from' => '2026-10-19', 'to' => '2026-10-19']));
    expect($totals['days']['field_work'])->toBe(1)
        ->and($totals['assessed_working_days'])->toBe(1)
        ->and(ReportTiles::fromTotals($totals)['present'])->toBe(1)
        ->and(ReportTiles::fromTotals($totals)['field_work'])->toBe(1);

    app(Holidays::class)->withdraw($holiday->fresh(), $this->actor, 'Event ended');
    expect(holidayAssess($this->company, $selected)->status)->toBe('pending');
});

test('employee ids and departments are an or group intersected with branch', function (): void {
    $first = holidayEmployee($this->company, $this->branch, ['department' => 'Events']);
    $second = holidayEmployee($this->company, $this->branch, ['department' => 'Accounts']);
    $otherBranch = Branch::factory()->create(['company_id' => $this->company->id]);
    $third = holidayEmployee($this->company, $otherBranch, ['department' => 'Events']);
    $holiday = holidayDraft($this->company, $this->actor, [
        'kind' => 'field_work', 'departments' => ['events'], 'employee_ids' => [$second->id],
        'branch_ids' => [$this->branch->id],
    ]);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Off-site work');

    expect(holidayAssess($this->company, $first)->status)->toBe('field_work')
        ->and(holidayAssess($this->company, $second)->status)->toBe('field_work')
        ->and(holidayAssess($this->company, $third)->status)->toBe('pending');
});

test('leave still counts a field day as a working date while a holiday is skipped', function (): void {
    $employee = holidayEmployee($this->company, $this->branch);
    $field = holidayDraft($this->company, $this->actor, [
        'kind' => 'field_work', 'employee_ids' => [$employee->id],
    ]);
    app(Holidays::class)->approve($field, [], $this->actor, 'Off-site work');
    $holidayDates = array_keys(app(HolidayCalendar::class)->forEmployeeDates($this->company,
        collect([$employee]), '2026-10-19', '2026-10-19')[$employee->id] ?? []);
    $request = ['start_date' => '2026-10-19', 'end_date' => '2026-10-19', 'duration_days' => 1];
    $policies = [['effective_from' => '2020-01-01', 'version' => 1,
        'office_rest_days' => [5, 6], 'shift_rest_days' => [5]]];
    $versions = [['effective_from' => '2020-01-01', 'id' => 1,
        'changes' => ['work_pattern' => 'office']]];
    expect($holidayDates)->toBe([])
        ->and(app(LeaveDistributor::class)->distribute($request, $versions, $policies, holidayDates: $holidayDates)['days'][0]['work_date'])
        ->toBe('2026-10-19');

    $holiday = holidayDraft($this->company, $this->actor, ['kind' => 'holiday']);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Company holiday');
    $holidayDates = array_keys(app(HolidayCalendar::class)->forEmployeeDates($this->company,
        collect([$employee]), '2026-10-19', '2026-10-19')[$employee->id] ?? []);
    expect($holidayDates)->toBe(['2026-10-19']);
});

test('field work keeps late and single punch evidence while removing time penalties', function (): void {
    $late = holidayEmployee($this->company, $this->branch);
    $single = holidayEmployee($this->company, $this->branch);
    foreach ([['09:20:00', 'check_in'], ['17:00:00', 'check_out']] as [$clock, $role]) {
        ManualPunch::factory()->create(['employee_id' => $late->id, 'created_by' => $this->actor->id,
            'punched_at' => '2026-10-19 '.$clock, 'role' => $role]);
    }
    ManualPunch::factory()->create(['employee_id' => $single->id, 'created_by' => $this->actor->id,
        'punched_at' => '2026-10-19 09:20:00', 'role' => 'check_in']);
    $field = holidayDraft($this->company, $this->actor,
        ['kind' => 'field_work', 'employee_ids' => [$late->id, $single->id]]);
    app(Holidays::class)->approve($field, [], $this->actor, 'Market day');

    foreach ([$late, $single] as $employee) {
        $day = holidayAssess($this->company, $employee);
        expect($day->status)->toBe('field_work')
            ->and($day->first_in)->not->toBeNull()
            ->and($day->late_minutes)->toBe(0)
            ->and($day->early_minutes)->toBe(0)
            ->and($day->short_minutes)->toBe(0)
            ->and($day->balance_minutes)->toBe(0)
            ->and($day->overtime_minutes)->toBeNull();
    }
    expect(holidayAssess($this->company, $late)->last_out)->not->toBeNull()
        ->and(holidayAssess($this->company, $single)->last_out)->toBeNull();
});

test('field work clears earlier overtime fields and withdrawal restores ordinary overtime', function (): void {
    $employee = holidayEmployee($this->company, $this->branch);
    CoverageInterval::factory()->create(['company_id' => $this->company->id,
        'batch_id' => ImportBatch::factory()->create(['company_id' => $this->company->id])->id,
        'branch_id' => $this->branch->id, 'coverage_from' => '2026-10-19 00:00:00',
        'coverage_to' => '2026-10-20 23:59:59', 'is_confirmed' => true]);
    foreach ([['07:30:00', 'check_in'], ['19:00:00', 'check_out']] as [$clock, $role]) {
        ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $this->actor->id,
            'punched_at' => '2026-10-19 '.$clock, 'role' => $role]);
    }
    $ordinary = holidayAssess($this->company, $employee);
    expect($ordinary->status)->toBe('present')
        ->and($ordinary->overtime_minutes)->toBeGreaterThan(0)
        ->and($ordinary->early_arrival_minutes)->toBeGreaterThan(0);

    $field = holidayDraft($this->company, $this->actor,
        ['kind' => 'field_work', 'employee_ids' => [$employee->id]]);
    app(Holidays::class)->approve($field, [], $this->actor, 'Event day');
    $covered = holidayAssess($this->company, $employee);
    expect($covered->status)->toBe('field_work')
        ->and($covered->overtime_minutes)->toBeNull()
        ->and($covered->early_arrival_minutes)->toBeNull()
        ->and($covered->overtime_basis)->toBeNull()
        ->and($ordinary->fresh()->superseded_by_run_id)->not->toBeNull();

    app(Holidays::class)->withdraw($field->fresh(), $this->actor, 'Event withdrawn');
    $restored = holidayAssess($this->company, $employee);
    expect($restored->status)->toBe('present')
        ->and($restored->overtime_minutes)->toBeGreaterThan(0);
});

test('rest days and approved holidays win over field work', function (): void {
    $rest = holidayEmployee($this->company, $this->branch, ['rest_days' => [1]]);
    $ordinary = holidayEmployee($this->company, $this->branch);
    $field = holidayDraft($this->company, $this->actor, ['kind' => 'field_work',
        'employee_ids' => [$rest->id, $ordinary->id]]);
    app(Holidays::class)->approve($field, [], $this->actor, 'Market day');
    $holiday = holidayDraft($this->company, $this->actor, ['kind' => 'holiday',
        'employee_ids' => [$ordinary->id]]);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Public holiday');

    expect(holidayAssess($this->company, $rest)->status)->toBe('rest_day')
        ->and(holidayAssess($this->company, $ordinary)->status)->toBe('holiday');
});

test('holiday scope rejects a branch belonging to another company', function (): void {
    $otherCompany = Company::factory()->create();
    $otherBranch = app(TenantContext::class)->runForCompany($otherCompany,
        fn (): Branch => Branch::factory()->create());

    expect(fn () => holidayDraft($this->company, $this->actor, [
        'branch_ids' => [$otherBranch->id],
    ]))->toThrow(ValidationException::class);
});

test('announcement scope rejects employee ids from another company', function (): void {
    $otherCompany = Company::factory()->create();
    $other = app(TenantContext::class)->runForCompany($otherCompany,
        fn (): Employee => Employee::factory()->create(['company_id' => $otherCompany->id]));
    expect(fn () => holidayDraft($this->company, $this->actor,
        ['employee_ids' => [$other->id]]))->toThrow(ValidationException::class);
});

test('rejecting a draft records a version without changing attendance', function (): void {
    $employee = holidayEmployee($this->company, $this->branch);
    $holiday = holidayDraft($this->company, $this->actor);
    Queue::fake();

    $version = app(Holidays::class)->reject($holiday, $this->actor, 'Announcement was retracted');
    expect($version->status)->toBe('rejected')
        ->and($version->version)->toBe(2)
        ->and(holidayAssess($this->company, $employee)->status)->toBe('pending');
    Queue::assertNotPushed(AssessDaysJob::class);
});

test('future-only holidays queue no assessment and no leave matching for employees without leave', function (): void {
    holidayEmployee($this->company, $this->branch);
    $holiday = holidayDraft($this->company, $this->actor, [
        'ranges' => [['from' => '2026-11-19', 'to' => '2026-11-19']],
    ]);
    Queue::fake();

    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published in advance');
    Queue::assertNotPushed(AssessDaysJob::class);
    Queue::assertNotPushed(ReconcileLeaveJob::class);
});

test('legal entity branch and pattern scopes use historical values and overlapping ids', function (): void {
    $covered = holidayEmployee($this->company, $this->branch, ['legal_entity' => 'Al-Waha']);
    $other = holidayEmployee($this->company, $this->branch, ['legal_entity' => 'Sardkar']);
    $otherBranch = Branch::factory()->create();
    $wrongBranch = holidayEmployee($this->company, $otherBranch, ['legal_entity' => 'Al-Waha']);
    $wrongPattern = holidayEmployee($this->company, $this->branch, [
        'legal_entity' => 'Al-Waha', 'work_pattern' => 'shift',
    ]);
    $holiday = holidayDraft($this->company, $this->actor, ['legal_entities' => ['Al-Waha'], 'branch_ids' => [$this->branch->id],
        'work_patterns' => ['office']]);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Company announcement');
    $second = holidayDraft($this->company, $this->actor, ['name_en' => 'National Day']);
    app(Holidays::class)->approve($second, [], $this->actor, 'National announcement');

    $calendar = app(HolidayCalendar::class)->forEmployeeDates($this->company,
        collect([$covered, $other, $wrongBranch, $wrongPattern]),
        '2026-10-19', '2026-10-19');
    expect($calendar[$covered->id]['2026-10-19'])->toBe([$holiday->id, $second->id])
        ->and($calendar[$other->id]['2026-10-19'])->toBe([$second->id])
        ->and($calendar[$wrongBranch->id]['2026-10-19'])->toBe([$second->id])
        ->and($calendar[$wrongPattern->id]['2026-10-19'])->toBe([$second->id]);
    expect(holidayAssess($this->company, $covered)->holiday_ids)->toBe([$holiday->id, $second->id]);
});

test('a back-dated employee legal entity change changes only matching holiday dates', function (): void {
    $employee = holidayEmployee($this->company, $this->branch, ['legal_entity' => 'Sardkar']);
    $holiday = holidayDraft($this->company, $this->actor, [
        'ranges' => [['from' => '2026-10-19', 'to' => '2026-10-20']], 'legal_entities' => ['Al-Waha'],
    ]);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');
    app(EmployeeHistory::class)->record($employee, ['legal_entity' => 'Al-Waha'],
        '2026-10-20', 'manual', $this->actor);

    $calendar = app(HolidayCalendar::class)->forEmployeeDates($this->company, collect([$employee]),
        '2026-10-19', '2026-10-20');
    expect($calendar[$employee->id]['2026-10-19'] ?? [])->toBe([])
        ->and($calendar[$employee->id]['2026-10-20'])->toBe([$holiday->id]);
});

test('summary drill-down and export use the same holiday result', function (): void {
    $employee = holidayEmployee($this->company, $this->branch);
    $holiday = holidayDraft($this->company, $this->actor);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');
    holidayAssess($this->company, $employee);

    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-19', 'to' => '2026-10-19']);
    $summary = app(AttendanceSummary::class);
    $export = AttendanceExport::factory()->create(['requested_by' => $this->actor->id]);
    $rows = iterator_to_array(app(ExportData::class)->rows($export, $filters), false);

    expect($summary->totals($filters)['days']['holiday'])->toBe(1)
        ->and($summary->drillDown($filters, 'holiday'))->toBe([$employee->id])
        ->and($rows)->toHaveCount(1)
        ->and($rows[0]['status'])->toBe(__('messages.office_ui.statuses.holiday'));
});

test('worked holidays record presence without lateness and a night shift belongs to its start date', function (): void {
    $employee = holidayEmployee($this->company, $this->branch, ['work_pattern' => 'shift', 'rest_days' => []]);
    $holiday = holidayDraft($this->company, $this->actor, ['work_patterns' => ['shift']]);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');
    foreach ([['2026-10-19 16:00:00', 'check_in'], ['2026-10-20 00:00:00', 'check_out']] as [$time, $role]) {
        ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $this->actor->id,
            'punched_at' => $time, 'role' => $role]);
    }

    $day = holidayAssess($this->company, $employee);
    expect($day->status)->toBe('holiday_worked')
        ->and($day->presence_minutes)->toBe(480)
        ->and($day->late_minutes)->toBe(0)
        ->and($day->balance_minutes)->toBe(0)
        ->and($day->holiday_ids)->toBe([$holiday->id]);
    $totals = app(AttendanceSummary::class)->totals(ReportFilters::fromArray($this->company, [
        'from' => '2026-10-19', 'to' => '2026-10-19',
    ]));
    expect($totals['holiday_work_minutes'])->toBe(480)
        ->and($totals['assessed_working_days'])->toBe(0);
    $next = holidayAssess($this->company, $employee, '2026-10-20');
    expect($next->holiday_ids)->toBeNull()
        ->and($next->status)->not->toBe('holiday_worked');
});

test('a holiday on a worked rest day keeps the rest-day family and its holiday ids', function (): void {
    $employee = holidayEmployee($this->company, $this->branch, ['rest_days' => [1]]);
    $holiday = holidayDraft($this->company, $this->actor);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');
    foreach ([['08:00:00', 'check_in'], ['12:00:00', 'check_out']] as [$clock, $role]) {
        ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $this->actor->id,
            'punched_at' => '2026-10-19 '.$clock, 'role' => $role]);
    }

    $day = holidayAssess($this->company, $employee);
    expect($day->status)->toBe('rest_day_worked')
        ->and($day->presence_minutes)->toBe(240)
        ->and($day->holiday_ids)->toBe([$holiday->id]);
    $totals = app(AttendanceSummary::class)->totals(ReportFilters::fromArray($this->company, [
        'from' => '2026-10-19', 'to' => '2026-10-19',
    ]));
    expect($totals['rest_day_work_minutes'])->toBe(240)
        ->and($totals['days']['holiday_worked'])->toBe(0);
});

test('a night shift starting before a holiday remains on its non-holiday work date', function (): void {
    $employee = holidayEmployee($this->company, $this->branch, ['work_pattern' => 'shift', 'rest_days' => []]);
    $holiday = holidayDraft($this->company, $this->actor, [
        'ranges' => [['from' => '2026-10-20', 'to' => '2026-10-20']],
    ]);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');
    foreach ([['2026-10-19 16:00:00', 'check_in'], ['2026-10-20 00:00:00', 'check_out']] as [$time, $role]) {
        ManualPunch::factory()->create(['employee_id' => $employee->id, 'created_by' => $this->actor->id,
            'punched_at' => $time, 'role' => $role]);
    }

    $startDay = holidayAssess($this->company, $employee);
    $holidayDay = holidayAssess($this->company, $employee, '2026-10-20');
    expect($startDay->holiday_ids)->toBeNull()
        ->and($startDay->status)->not->toBe('holiday_worked')
        ->and($holidayDay->status)->toBe('holiday')
        ->and($holidayDay->holiday_ids)->toBe([$holiday->id]);
});

test('editing and withdrawing a holiday supersedes the day and queues both recalculations', function (): void {
    $employee = holidayEmployee($this->company, $this->branch);
    $holiday = holidayDraft($this->company, $this->actor);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');
    $first = holidayAssess($this->company, $employee);
    Bus::fake();

    app(Holidays::class)->edit($holiday->fresh(), ['ranges' => [['from' => '2026-10-20', 'to' => '2026-10-20']]],
        $this->actor, 'Corrected date');
    $replacement = holidayAssess($this->company, $employee);
    expect($replacement->status)->not->toBe('holiday')
        ->and($first->fresh()->superseded_by_run_id)->not->toBeNull()
        ->and($replacement->changes['holiday_ids']['before'])->toBe([$holiday->id]);
    Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->allowsFailures() && $batch->jobs->flatten()->contains(
        fn ($job): bool => $job instanceof AssessDaysJob && $job->scope['employee_ids'] === [$employee->id]
            && $job->scope['from'] === '2026-10-18' && $job->scope['to'] === '2026-10-21'));

    app(Holidays::class)->withdraw($holiday->fresh(), $this->actor, 'Withdrawn notice');
    Bus::assertBatchCount(2);
    expect(HolidayVersion::query()->where('holiday_id', $holiday->id)->count())->toBe(4)
        ->and(app(HolidayCalendar::class)->forEmployeeDates($this->company, collect([$employee]),
            '2026-10-20', '2026-10-20'))->toBe([]);
});

test('holiday approval collects only overlapping leave requests and leaves assessment in its batch', function (): void {
    config(['queue.default' => 'database']);
    $overlap = holidayEmployee($this->company, $this->branch);
    $outside = holidayEmployee($this->company, $this->branch);
    holidayEmployee($this->company, $this->branch);
    $type = LeaveType::factory()->create(['covers' => true, 'is_mapped' => true]);
    foreach ([[$overlap, '2026-10-18', '2026-10-22', 'pending'], [$outside, '2026-11-01', '2026-11-02', 'approved']] as [$employee, $from, $to, $status]) {
        LeaveRequest::factory()->create(['company_id' => $this->company->id,
            'employee_id' => $employee->id, 'owner_code' => $employee->employee_code,
            'leave_type_id' => $type->id, 'status' => $status, 'start_date' => $from, 'end_date' => $to]);
    }
    $holiday = holidayDraft($this->company, $this->actor);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');

    event(new DaysAssessed($this->company->id, [$overlap->id], '2026-10-19', '2026-10-19'));

    expect(PendingLeaveReconciliation::query()->where('company_id', $this->company->id)
        ->pluck('employee_id')->all())->toBe([$overlap->id])
        ->and((int) PendingLeaveReconciliation::query()->where('company_id', $this->company->id)
            ->where('employee_id', $overlap->id)->value('revision'))->toBe(1)
        ->and($holiday->fresh()->reprocess_batch_id)->not->toBeNull();
    Queue::assertNotPushed(ReconcileLeaveJob::class,
        fn (ReconcileLeaveJob $job): bool => $job->trigger === 'holiday');
});

test('future holiday collects overlapping leave without creating an empty assessment batch', function (): void {
    config(['queue.default' => 'database']);
    $employee = holidayEmployee($this->company, $this->branch);
    $type = LeaveType::factory()->create(['covers' => true, 'is_mapped' => true]);
    LeaveRequest::factory()->create(['company_id' => $this->company->id, 'employee_id' => $employee->id,
        'owner_code' => $employee->employee_code, 'leave_type_id' => $type->id,
        'start_date' => '2026-11-01', 'end_date' => '2026-11-03']);
    $holiday = holidayDraft($this->company, $this->actor,
        ['ranges' => [['from' => '2026-11-02', 'to' => '2026-11-02']]]);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');

    expect($holiday->fresh()->reprocess_batch_id)->toBeNull()
        ->and(PendingLeaveReconciliation::query()->where('company_id', $this->company->id)
            ->pluck('employee_id')->all())->toBe([$employee->id]);
    Queue::assertNotPushed(AssessDaysJob::class);
    Queue::assertNotPushed(ReconcileLeaveJob::class,
        fn (ReconcileLeaveJob $job): bool => $job->trigger === 'holiday');
});

test('all leave counting methods exclude holidays and never place minutes on one', function (): void {
    $versions = [['id' => 1, 'effective_from' => '2020-01-01', 'changes' => [
        'work_pattern' => 'office', 'rest_days' => [5, 6],
    ]]];
    $policies = [['effective_from' => '2020-01-01', 'version' => 1, 'office_rest_days' => [5, 6]]];
    $distributor = app(LeaveDistributor::class);
    $result = $distributor->distribute(['start_date' => '2026-10-18', 'end_date' => '2026-10-22',
        'duration_days' => 4], $versions, $policies, holidayDates: ['2026-10-19']);
    expect($result['counting_method'])->toBe('employee')
        ->and(array_column($result['days'], 'work_date'))->toBe(['2026-10-18', '2026-10-20', '2026-10-21', '2026-10-22']);

    $counted = $distributor->distribute(['start_date' => '2026-10-18', 'end_date' => '2026-10-22',
        'duration_days' => 5], $versions, $policies, holidayDates: ['2026-10-19']);
    expect($counted['flag'])->toBe('counted_holiday')
        ->and(array_column($counted['days'], 'work_date'))->not->toContain('2026-10-19');
    $explicit = $distributor->distribute(['start_date' => '2026-10-18', 'end_date' => '2026-10-22',
        'duration_days' => 1], $versions, $policies,
        ['mode' => 'dates', 'dates' => ['2026-10-19']], holidayDates: ['2026-10-19']);
    expect($explicit['flag'])->toBe('duration_mismatch');

    $half = $distributor->distribute(['start_date' => '2026-10-18', 'end_date' => '2026-10-20',
        'duration_days' => 1.5], $versions, $policies, holidayDates: ['2026-10-19']);
    expect(array_column($half['days'], 'minutes'))->toBe([480, 240])
        ->and(array_column($half['days'], 'work_date'))->toBe(['2026-10-18', '2026-10-20']);

    $leaveCalendar = $distributor->distribute(['start_date' => '2026-10-18', 'end_date' => '2026-10-24',
        'duration_days' => 5], $versions, $policies, holidayDates: ['2026-10-19']);
    expect($leaveCalendar['counting_method'])->toBe('leave_calendar')
        ->and(array_column($leaveCalendar['days'], 'work_date'))->not->toContain('2026-10-19');

    $calendar = $distributor->distribute(['start_date' => '2026-10-23', 'end_date' => '2026-10-25',
        'duration_days' => 2], $versions, $policies, holidayDates: ['2026-10-24']);
    expect($calendar['counting_method'])->toBe('calendar')
        ->and(array_column($calendar['days'], 'work_date'))->toBe(['2026-10-25']);
});

test('reconciliation flags a counted holiday and distributes minutes on working dates only', function (): void {
    $employee = holidayEmployee($this->company, $this->branch);
    $holiday = holidayDraft($this->company, $this->actor);
    app(Holidays::class)->approve($holiday, [], $this->actor, 'Published');
    $type = LeaveType::factory()->create(['covers' => true, 'is_mapped' => true]);
    $request = LeaveRequest::factory()->create([
        'employee_id' => $employee->id, 'owner_code' => $employee->employee_code,
        'leave_type_id' => $type->id, 'status' => 'approved', 'start_date' => '2026-10-18',
        'end_date' => '2026-10-22', 'duration_days' => 5,
    ]);
    app(Reconciler::class)->run($this->company, [$employee->id], 'holiday-test');

    expect($request->fresh()->flags)->toContain('counted_holiday')
        ->and(RequestDay::query()->where('request_id', $request->id)
            ->whereNull('superseded_by_run_id')->orderBy('work_date')->get()
            ->map(fn (RequestDay $day): string => $day->work_date->toDateString())->all())
        ->toBe(['2026-10-18', '2026-10-20', '2026-10-21', '2026-10-22']);
});
