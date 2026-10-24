<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\DailyAttendance;
use App\Filament\Admin\Pages\Attendance\DayDetail;
use App\Filament\Admin\Pages\Attendance\OfficePolicyPage;
use App\Filament\Admin\Pages\Attendance\ReviewQueue;
use App\Jobs\Attendance\AssessDaysJob;
use App\Livewire\Attendance\DashboardEmployees;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\Day;
use App\Models\Attendance\DayDecision;
use App\Models\Attendance\ManualPunch;
use App\Models\Attendance\Policy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\DayReview;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-22 12:00:00');
    Queue::fake([AssessDaysJob::class]);
    $this->other = Company::factory()->create();
    $this->foreign = app(TenantContext::class)->runForCompany($this->other, function (): array {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $branch->id, 'work_pattern' => 'shift']);
        $policy = Policy::factory()->create(['effective_from' => '2026-10-20', 'version' => 1]);
        $day = Day::factory()->create([
            'employee_id' => $employee->id, 'branch_id' => $branch->id, 'policy_id' => $policy->id,
            'work_date' => '2026-10-21', 'status' => 'shift_unresolved', 'inputs' => ['work_pattern' => 'shift'],
        ]);

        return compact('employee', 'day');
    });
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create();
    $this->employee = Employee::factory()->create([
        'branch_id' => $this->branch->id, 'work_pattern' => 'shift', 'name' => 'Local Shift Worker',
    ]);
    $this->officeEmployee = Employee::factory()->create([
        'branch_id' => $this->branch->id, 'work_pattern' => 'office', 'name' => 'Office Colleague',
    ]);
    $this->hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->policy = Policy::factory()->create([
        'effective_from' => '2026-10-20', 'version' => 1, 'shift_break_minutes' => 37,
        'security_min_presence_minutes' => 470,
    ]);
    $this->day = Day::factory()->create([
        'employee_id' => $this->employee->id, 'branch_id' => $this->branch->id, 'policy_id' => $this->policy->id,
        'work_date' => '2026-10-21', 'status' => 'shift_unresolved', 'shift_source' => 'unknown',
        'shift_confidence' => 'unresolved', 'inputs' => ['work_pattern' => 'shift'],
    ]);
    Day::factory()->create([
        'employee_id' => $this->officeEmployee->id, 'branch_id' => $this->branch->id,
        'work_date' => '2026-10-21', 'status' => 'absent', 'inputs' => ['work_pattern' => 'office'],
    ]);
    actingInAdminTenant($this, $this->hr, $this->company);
});

afterEach(function (): void {
    Carbon::setTestNow();
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
    app()->setLocale('en');
});

test('HR picks and withdraws a shift through the review queue without changing another company', function (): void {
    Livewire::test(ReviewQueue::class)->set('reviewState', 'needs_shift')
        ->assertSee('Local Shift Worker')->assertDontSee('Office Colleague')->assertDontSee($this->foreign['employee']->name)
        ->call('selectReviewDay', $this->day->id)->set('reviewShiftStart', '08:00')
        ->set('reviewReason', 'Verified roster')->call('pickShift')->assertHasNoErrors();
    $choice = DayDecision::query()->where('employee_id', $this->employee->id)->sole();
    expect($choice->decision)->toBe('pick_shift')->and($choice->shift_start)->toBe('08:00:00');
    Livewire::withQueryParams(['employee' => $this->employee->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)->assertSee('Withdraw shift choice');

    Livewire::test(ReviewQueue::class)->set('reviewState', 'needs_shift')
        ->call('selectReviewDay', $this->day->id)->set('reviewReason', 'Roster corrected')
        ->call('withdrawLatest', $this->day->id)->assertHasNoErrors();
    expect(DayDecision::query()->where('employee_id', $this->employee->id)->orderBy('id')->pluck('decision')->all())
        ->toBe(['pick_shift', 'withdraw_shift']);
    Queue::assertPushed(AssessDaysJob::class);
});

test('platform admin may choose a shift and company HR cannot choose another company shift', function (): void {
    Livewire::test(ReviewQueue::class)->call('selectReviewDay', $this->foreign['day']->id)->assertNotFound();
    expect(fn () => app(DayReview::class)->pickShift($this->foreign['employee'], '2026-10-21', '08:00', 'Wrong company', $this->hr))
        ->toThrow(HttpException::class);
    expect(DayDecision::query()->count())->toBe(0);

    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    actingInAdminTenant($this, $platform, $this->company);
    Livewire::test(ReviewQueue::class)->call('selectReviewDay', $this->day->id)
        ->set('reviewShiftStart', '16:00')->set('reviewReason', 'Confirmed with HR')
        ->call('pickShift')->assertHasNoErrors();
    expect(DayDecision::query()->where('employee_id', $this->employee->id)->value('shift_start'))->toBe('16:00:00');
    Livewire::test(ReviewQueue::class)->call('selectReviewDay', $this->day->id)
        ->set('reviewReason', 'Choice withdrawn')->call('withdrawLatest', $this->day->id)->assertHasNoErrors();
    expect(DayDecision::query()->where('employee_id', $this->employee->id)->orderBy('id')->pluck('decision')->all())
        ->toBe(['pick_shift', 'withdraw_shift']);
});

test('an evening manual checkout appears in reviewed days and can be withdrawn', function (): void {
    $this->day->update(['status' => 'missing_punch', 'shift_start' => '16:00:00',
        'first_in' => '2026-10-21 16:00:00']);
    Livewire::test(ReviewQueue::class)->call('selectReviewDay', $this->day->id)
        ->set('reviewRole', 'check_out')->set('reviewTime', '00:00')
        ->set('reviewReason', 'Verified checkout')->call('addMissingTime')->assertHasNoErrors();
    $manual = ManualPunch::query()->where('employee_id', $this->employee->id)->sole();
    expect($manual->punched_at->format('Y-m-d H:i'))->toBe('2026-10-22 00:00');
    Livewire::test(ReviewQueue::class)->set('reviewState', 'reviewed')
        ->assertSee('Local Shift Worker')->call('selectReviewDay', $this->day->id)
        ->set('reviewReason', 'Correction withdrawn')->call('withdrawLatest', $this->day->id)
        ->assertHasNoErrors();
    expect(ManualPunch::query()->where('reverses_id', $manual->id)->exists())->toBeTrue();
});

test('daily status and dashboard needs shift count use the same drill down', function (): void {
    Livewire::test(DailyAttendance::class)->set('status', 'shift_unresolved')
        ->assertSee('Local Shift Worker')->assertSee('Unknown')
        ->assertDontSee('Office Colleague')->assertDontSee($this->foreign['employee']->name);
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-21', 'to' => '2026-10-21']);
    $summary = app(AttendanceSummary::class);
    expect($summary->totals($filters)['days']['shift_unresolved'])->toBe(1)
        ->and($summary->totals($filters)['assessed_working_days'])->toBe(1)
        ->and(ReportTiles::fromTotals($summary->totals($filters))['shift_unresolved'])->toBe(1)
        ->and($summary->drillDown($filters, 'shift_unresolved'))->toBe([$this->employee->id]);
    Livewire::test(HrDashboard::class)->assertSee('Needs shift');
    Livewire::withQueryParams(['tile' => 'shift_unresolved', 'from' => '2026-10-21', 'to' => '2026-10-21'])
        ->test(DashboardEmployees::class)->assertSee('Local Shift Worker')->assertDontSee('Office Colleague');
});

test('day detail shows shift source confidence evidence and its policy', function (): void {
    $this->day->update([
        'shift_start' => '08:00:00', 'shift_source' => 'inferred', 'shift_confidence' => 'leave_explained',
        'shift_evidence' => ['first_in' => '2026-10-21 12:00:00', 'last_out' => '2026-10-21 16:00:00',
            'approved_leave_minutes' => 240],
    ]);
    Livewire::withQueryParams(['employee' => $this->employee->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)->assertSee('08:00–16:00')->assertSee('Inferred')
        ->assertSee('Explained by leave')->assertSee('2026-10-21 12:00:00')
        ->assertSee('240')->assertSee('Shift short-hours threshold');
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $this->employee->id])
        ->assertSee('08:00–16:00')->assertSee('Inferred');
});

test('attendance policy sections keep unedited shift and security values in a new version', function (): void {
    Policy::factory()->create([
        'effective_from' => '2026-10-19', 'version' => 2, 'shift_break_minutes' => 55,
        'security_min_presence_minutes' => 460,
    ]);
    Livewire::test(OfficePolicyPage::class)->assertSee('Attendance policy')->assertSee('Office')
        ->assertSee('Shift')->assertSee('Security')->assertSee('Shift inference')
        ->set('effectiveFrom', '2026-10-19')->set('flexMinutes', 45)
        ->set('reason', 'Approved flexibility')->call('addVersion')->assertHasNoErrors();
    $latest = Policy::query()->where('company_id', $this->company->id)->orderByDesc('version')->firstOrFail();
    expect($latest->flex_minutes)->toBe(45)
        ->and($latest->shift_break_minutes)->toBe(55)
        ->and($latest->security_min_presence_minutes)->toBe(460)
        ->and($latest->absence_minutes)->toBe($this->policy->absence_minutes)
        ->and($latest->shift_starts)->toBe($this->policy->shift_starts);
});

test('statement export labels unresolved shifts in English and Arabic', function (): void {
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-21', 'to' => '2026-10-21',
        'employee_ids' => [$this->employee->id]]);
    $export = AttendanceExport::factory()->create(['kind' => 'employee_statement_xlsx']);
    $data = app(ExportData::class);
    expect(iterator_to_array($data->rows($export, $filters), false)[0]['status'])->toBe('Shift needs HR review');
    app()->setLocale('ar');
    expect(iterator_to_array($data->rows($export, $filters), false)[0]['status'])
        ->toBe(__('messages.office_ui.statuses.shift_unresolved'));
});
