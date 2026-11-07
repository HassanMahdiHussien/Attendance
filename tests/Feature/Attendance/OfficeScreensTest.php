<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\DailyAttendance;
use App\Filament\Admin\Pages\Attendance\DayDetail;
use App\Filament\Admin\Pages\Attendance\OfficePolicyPage;
use App\Filament\Admin\Pages\Attendance\ReviewQueue;
use App\Jobs\Attendance\AssessDaysJob;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Policy;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-22 12:00:00');
    Filament::setTenant(null);
    $this->company = Company::factory()->create();
    $this->other = Company::factory()->create();
    $this->foreign = app(TenantContext::class)->runForCompany($this->other, function (): array {
        $branch = Branch::factory()->create(['company_id' => $this->other->id]);
        $employee = Employee::factory()->create([
            'company_id' => $this->other->id, 'branch_id' => $branch->id,
            'name' => 'Foreign employee', 'hire_date' => '2026-10-20', 'work_pattern' => 'office',
        ]);
        $day = Day::factory()->create(['company_id' => $this->other->id, 'employee_id' => $employee->id,
            'branch_id' => $branch->id, 'work_date' => '2026-10-21', 'status' => 'absent']);

        return compact('branch', 'employee', 'day');
    });
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
        'name' => 'Local employee', 'hire_date' => '2026-10-20', 'work_pattern' => 'office']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->policy = Policy::factory()->create(['company_id' => $this->company->id, 'effective_from' => '2026-10-20', 'version' => 1]);
    $this->day = Day::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id, 'policy_id' => $this->policy->id,
        'work_date' => '2026-10-21', 'status' => 'absent', 'inputs' => ['policy_version' => 1, 'work_pattern' => 'office'],
    ]);
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Carbon::setTestNow();
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('daily table filters by date branch and status and opens the day detail', function (): void {
    Livewire::test(DailyAttendance::class)->assertSet('date', '2026-10-21')
        ->assertSee('Local employee')->assertSee('Absent')->assertDontSee('Foreign employee')
        ->set('status', 'present')->assertDontSee('Local employee')->assertSee('Absent')
        ->set('status', 'absent')->set('branchId', (string) $this->branch->id)->assertSee('Local employee')
        ->set('date', '2026-10-20')->assertDontSee('Local employee');
    Livewire::withQueryParams(['employee' => $this->employee->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)->assertSee('Local employee')->assertSee('Recalculation history');
});

test('a pending day stays neutral in the daily and detail presentations', function (): void {
    $pending = Day::factory()->create([
        'company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id, 'policy_id' => $this->policy->id,
        'work_date' => '2026-10-20', 'status' => 'pending',
        'inputs' => ['policy_version' => 1, 'work_pattern' => 'office'],
    ]);

    $page = Livewire::test(DailyAttendance::class)->set('date', '2026-10-20');
    expect($page->instance()->dayPresentations()[$pending->id])->toMatchArray([
        'status_key' => 'pending', 'tone' => 'gray', 'needs_review' => false,
    ]);
    $detail = Livewire::withQueryParams(['employee' => (string) $this->employee->id, 'date' => '2026-10-20'])->test(DayDetail::class);
    expect($detail->instance()->dayPresentation())->toMatchArray([
        'status_key' => 'pending', 'tone' => 'gray', 'needs_review' => false,
    ]);
});

test('review queue adds a manual time and withdraws a confirmed absence', function (): void {
    Queue::fake([AssessDaysJob::class]);
    Livewire::test(ReviewQueue::class)->assertSee('Local employee')->assertDontSee('Foreign employee')
        ->call('selectReviewDay', $this->day->id)
        ->set('reviewRole', 'check_in')->set('reviewTime', '08:00')->set('reviewReason', 'Verified with manager')
        ->call('addMissingTime')->assertHasNoErrors();
    expect(ManualPunch::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
    Livewire::test(ReviewQueue::class)->call('selectReviewDay', $this->day->id)
        ->set('reviewReason', 'Checked attendance')->call('confirmAbsence')->assertHasNoErrors();
    expect(DayDecision::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
    $this->day->update(['reviewed' => true]);
    Livewire::test(ReviewQueue::class)->set('reviewState', 'reviewed')
        ->call('selectReviewDay', $this->day->id)->set('reviewReason', 'Decision corrected')
        ->call('withdrawLatest', $this->day->id)->assertHasNoErrors();
    expect(DayDecision::query()->where('employee_id', $this->employee->id)->count())->toBe(2);
    Queue::assertPushed(AssessDaysJob::class);
});

test('review date and branch filters do not mix another day into the queue', function (): void {
    Livewire::test(ReviewQueue::class)->set('from', '2026-10-20')->set('to', '2026-10-20')
        ->assertDontSee('Local employee')
        ->set('from', '2026-10-21')->set('to', '2026-10-21')
        ->set('branchId', (string) $this->branch->id)->assertSee('Local employee')
        ->set('branchId', (string) ($this->branch->id + 10000))->assertDontSee('Local employee');
});

test('day detail uses the same reviewed action and shows an earlier result', function (): void {
    Queue::fake([AssessDaysJob::class]);
    Livewire::withQueryParams(['employee' => (string) $this->employee->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)->assertSee('Attendance day')->assertSee('Policy version')
        ->call('selectReviewDay', $this->day->id)
        ->set('reviewReason', 'Checked original export')->call('confirmAbsence')->assertHasNoErrors();
    expect(DayDecision::query()->where('employee_id', $this->employee->id)->count())->toBe(1);
});

test('day detail shows rule inputs and recalculation changes in words, not raw data', function (): void {
    $this->branch->forceFill(['name' => 'Hilla'])->save();
    $inputs = ['policy_version' => 1, 'work_pattern' => 'office', 'branch_id' => $this->branch->id,
        'rest_days' => [5, 6], 'exit_status' => 'none'];
    $this->day->update(['inputs' => $inputs, 'changes' => [
        'inputs' => ['before' => null, 'after' => $inputs],
        'late_minutes' => ['before' => null, 'after' => 65],
        'intervals' => ['before' => [], 'after' => [['from' => '2026-10-21 09:05:00', 'to' => '2026-10-21 17:29:00']]],
    ]]);
    Livewire::withQueryParams(['employee' => (string) $this->employee->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)
        ->assertSee('Rest days: Friday, Saturday')->assertSee('Branch: Hilla')->assertSee('Work pattern: Office')
        ->assertSee('1 h 5 min')->assertSee('09:05–17:29')->assertSee('Employed')
        ->assertDontSee('"branch_id"')->assertDontSee('[5,6]')->assertDontSee('"from"');
});

test('office day times remain left to right in the Arabic interface', function (): void {
    $this->day->update(['first_in' => '2026-10-21 08:30:00', 'last_out' => '2026-10-21 17:30:00']);
    app()->setLocale('ar');
    Livewire::test(DailyAttendance::class)->assertSee('الحضور اليومي')->assertSee('dir="ltr"', escape: false);
});

test('attendance policy shows every pattern section and appends an effective version', function (): void {
    Queue::fake([AssessDaysJob::class]);
    Livewire::test(OfficePolicyPage::class)->assertSee('Attendance policy')->assertSee('Office')
        ->assertSee('Shift')->assertSee('Security')->assertSee('Policy history')
        ->set('effectiveFrom', '2026-10-21')->set('flexMinutes', 45)
        ->set('reason', 'Approved hours update')->call('addVersion')->assertHasNoErrors();
    $latest = Policy::query()->where('company_id', $this->company->id)->orderByDesc('version')->firstOrFail();
    expect($latest->version)->toBe(2)->and($latest->flex_minutes)->toBe(45)
        ->and($latest->reason)->toBe('Approved hours update');
});

test('policy history renders labelled columns and formatted durations', function (): void {
    Policy::factory()->create(['company_id' => $this->company->id, 'effective_from' => '2026-10-21',
        'version' => 2, 'reason' => 'office_rest_hour_480_from_policy:'.$this->policy->id]);
    Livewire::test(OfficePolicyPage::class)
        ->assertSee('data-label="Version"', escape: false)
        ->assertSee('data-label="Effective from"', escape: false)
        ->assertSee('1 h')
        ->assertSee('Rest hour')
        ->assertSee('Office rest hour: 8 hours worked in a 9-hour day');
});

test('admin daily row shows out time and its interval', function (): void {
    $this->day->update([
        'status' => 'present', 'first_in' => '2026-10-21 08:29:00', 'last_out' => '2026-10-21 17:53:00',
        'elapsed_minutes' => 564, 'presence_minutes' => 499, 'exit_minutes' => 65, 'short_minutes' => 0,
        'exits' => [['from' => '2026-10-21 12:50:00', 'to' => '2026-10-21 13:55:00', 'minutes' => 65]],
    ]);

    Livewire::test(DailyAttendance::class)->assertSee('Out')->assertSee('1 h 5 min')
        ->assertSee('5 min')->assertSee('12:50')->assertSee('13:55');
});

test('overtime appears on the daily and detail pages and policy edits retain other values', function (): void {
    $day = Day::factory()->create([
        'employee_id' => $this->employee->id, 'branch_id' => $this->branch->id,
        'policy_id' => $this->policy->id, 'work_date' => '2026-10-20',
        'status' => 'rest_day_worked', 'overtime_minutes' => 240,
        'overtime_basis' => 'rest_day', 'early_arrival_minutes' => 30,
        'inputs' => ['work_pattern' => 'office'],
    ]);
    RestDayEntitlement::factory()->create(['employee_id' => $this->employee->id,
        'source_day_id' => $day->id, 'work_date' => '2026-10-20', 'quantity' => 0.5]);
    Livewire::test(DailyAttendance::class)->set('date', '2026-10-20')
        ->assertSee('4 h')->assertSee('30 min')->assertSee('Rest day');
    Livewire::withQueryParams(['employee' => (string) $this->employee->id, 'date' => '2026-10-20'])
        ->test(DayDetail::class)->assertSee('Compensatory day earned')->assertSee('4 h');
    Queue::fake([AssessDaysJob::class]);
    Livewire::test(OfficePolicyPage::class)->assertSee('Overtime')
        ->set('effectiveFrom', '2026-10-21')->set('overtimeMinMinutes', 45)
        ->set('reason', 'Reviewed overtime minimum')->call('addVersion')->assertHasNoErrors();
    $latest = Policy::query()->where('company_id', $this->company->id)->orderByDesc('version')->firstOrFail();
    expect($latest->overtime_min_minutes)->toBe(45)
        ->and($latest->overtime_step_minutes)->toBe($this->policy->overtime_step_minutes)
        ->and($latest->compensatory_rest_days)->toEqual($this->policy->compensatory_rest_days);
});

test('minor gap tolerance is versioned, visible and limited to sixty minutes', function (): void {
    Queue::fake([AssessDaysJob::class]);
    Livewire::test(OfficePolicyPage::class)->assertSee('Allowed late and early minutes')
        ->assertSee('Applies to office, shift and security days')
        ->set('effectiveFrom', '2026-10-22')->set('minorGapToleranceMinutes', 61)
        ->set('reason', 'Invented policy review')->call('addVersion')->assertHasErrors(['minorGapToleranceMinutes']);

    Livewire::test(OfficePolicyPage::class)->set('effectiveFrom', '2026-10-22')
        ->set('minorGapToleranceMinutes', 0)->set('reason', 'Invented policy review')
        ->call('addVersion')->assertHasNoErrors();

    $latest = Policy::query()->where('company_id', $this->company->id)->orderByDesc('version')->firstOrFail();
    $default = DB::table('information_schema.columns')->where('table_schema', DB::getDatabaseName())
        ->where('table_name', 'attendance_policies')->where('column_name', 'minor_gap_tolerance_minutes')
        ->value('column_default');
    expect($latest->minor_gap_tolerance_minutes)->toBe(0)
        ->and($latest->version)->toBeGreaterThan($this->policy->version)
        ->and((int) $default)->toBe(5);
});

test('review actions and detail requests cannot cross company boundaries', function (): void {
    Livewire::test(ReviewQueue::class)->call('selectReviewDay', $this->foreign['day']->id)->assertNotFound();
    Livewire::withQueryParams(['employee' => $this->foreign['employee']->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)->assertNotFound();
    Livewire::test(DailyAttendance::class)->assertDontSee('Foreign employee');
    Livewire::test(OfficePolicyPage::class)->assertDontSee('Foreign employee');
});

test('all office pages reject another tenant HR and admit a platform admin', function (): void {
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    foreach ([DailyAttendance::class, ReviewQueue::class, OfficePolicyPage::class, DayDetail::class] as $page) {
        Filament::setTenant($this->other);
        if ($page === DayDetail::class) {
            Livewire::withQueryParams(['employee' => $this->foreign['employee']->id, 'date' => '2026-10-21'])
                ->test($page)->assertForbidden();
        } else {
            Livewire::test($page)->assertForbidden();
        }
        actingInAdminTenant($this, $platform, $this->other);
        if ($page === DayDetail::class) {
            Livewire::withQueryParams(['employee' => $this->foreign['employee']->id, 'date' => '2026-10-21'])
                ->test($page)->assertSee('Foreign employee');
        } else {
            Livewire::test($page)->assertSee($page::getNavigationLabel());
        }
        actingInAdminTenant($this, $this->actor, $this->company);
    }
});

test('saving a policy version confirms its date, lists the change and shows recalculation status', function (): void {
    Queue::fake([AssessDaysJob::class]);
    Livewire::test(OfficePolicyPage::class)->assertSee('Only days from this date use the new values')
        ->set('effectiveFrom', '2026-10-22')->set('minorGapToleranceMinutes', 15)
        ->set('reason', 'Invented tolerance test')->call('addVersion')->assertHasNoErrors()
        ->assertNotified('Version 2 saved. It applies to days from 2026-10-22.');

    // Tests rebuild months at once, so mark one as still waiting to see the in-progress line.
    DB::table('attendance_month_summaries')->insertOrIgnore(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'month' => '2026-09-01', 'version' => 1, 'is_dirty' => true,
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('attendance_month_summaries')->where('company_id', $this->company->id)->update(['is_dirty' => true]);
    Livewire::test(OfficePolicyPage::class)->assertSee('What changed')
        ->assertSee('Allowed late and early minutes: 5 min → 15 min')
        ->assertSee('employee-months are still being updated');

    DB::table('attendance_month_summaries')->where('company_id', $this->company->id)->update(['is_dirty' => false]);
    Livewire::test(OfficePolicyPage::class)->assertSee('All totals are up to date with the current policy.');
});

test('a back-dated version shows and saves the values in force on its date, keeping fields HR edited', function (): void {
    Queue::fake([AssessDaysJob::class]);
    Policy::factory()->create(['company_id' => $this->company->id, 'effective_from' => '2026-10-21',
        'version' => 2, 'minor_gap_tolerance_minutes' => 15]);

    Livewire::test(OfficePolicyPage::class)->assertSet('minorGapToleranceMinutes', 15)
        ->set('effectiveFrom', '2026-10-20')->assertSet('minorGapToleranceMinutes', 5)
        ->set('flexMinutes', 50)->set('effectiveFrom', '2026-10-21')
        ->assertSet('minorGapToleranceMinutes', 15)->assertSet('flexMinutes', 50)
        ->set('effectiveFrom', '2026-10-20')->set('minorGapToleranceMinutes', 12)
        ->set('reason', 'Invented back-dated test')->call('addVersion')->assertHasNoErrors();

    $latest = Policy::query()->where('company_id', $this->company->id)->orderByDesc('version')->firstOrFail();
    expect($latest->effective_from->toDateString())->toBe('2026-10-20')
        ->and($latest->minor_gap_tolerance_minutes)->toBe(12)
        ->and($latest->flex_minutes)->toBe(50);
});
