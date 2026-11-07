<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\TimeManagementPage;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\Day;
use App\Models\Attendance\Policy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function (): void {
    Filament::setTenant(null);
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create();
    $this->secondBranch = Branch::factory()->create();
    $this->policy = Policy::factory()->create(['shift_minutes' => 480]);
    $this->hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->hr, $this->company);
    Queue::fake();
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

function timeManagementPageEmployee(string $code, Branch $branch, string $department = 'Operations'): Employee
{
    return Employee::factory()->create(['employee_code' => $code, 'branch_id' => $branch->id,
        'department' => $department, 'work_pattern' => 'shift', 'hire_date' => '2020-01-01']);
}

function timeManagementPageDay(Employee $employee, Branch $branch, Policy $policy, string $date, array $values = []): Day
{
    return Day::factory()->create(array_replace([
        'employee_id' => $employee->id, 'branch_id' => $branch->id, 'policy_id' => $policy->id,
        'work_date' => $date, 'status' => 'present', 'inputs' => ['work_pattern' => 'shift'],
        'shift_start' => '00:00:00', 'first_in' => $date.' 00:00:00',
        'last_out' => $date.' 08:00:00', 'presence_minutes' => 480, 'overtime_minutes' => 60,
    ], $values));
}

test('HR sees the exact export day and employee total values with a missing check out marked', function (): void {
    $employee = timeManagementPageEmployee('0007', $this->branch);
    timeManagementPageDay($employee, $this->branch, $this->policy, '2026-10-16', ['last_out' => null]);
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
    $grouped = app(ExportData::class)->timeManagement($filters);

    $page = Livewire::test(TimeManagementPage::class)->set('month', '2026-10');
    expect($page->instance()->report())->toBe($grouped);
    expect($page->instance()->summary($grouped))->toMatchArray([
        'rows' => 1, 'nights' => 1, 'fridays' => 1, 'holidays' => 0,
        'overtime' => '1:00', 'missing_check_outs' => 1,
    ]);
    $page->assertSet('tab', 'night')->assertSee('Night shifts')->assertSee('Fridays');
    foreach ($grouped['night'][0] as $value) {
        if ($value !== '' && $value !== $grouped['night'][0]['shift']) {
            $page->assertSee((string) $value);
        }
    }
    $page->assertSee('bg-red-50', escape: false)->call('showFriday')->assertSee('0007')
        ->call('showHoliday')->assertSee('Employee code')->assertSee('No records match this view.')
        ->assertDontSee('0007')->call('showTotals');
    foreach ($grouped['totals'][0] as $value) {
        if ($value !== '') {
            $page->assertSee((string) $value);
        }
    }
    $page->assertSee(route('employees.show', $employee), escape: false);
    Livewire::withQueryParams(['from' => '2026-10-01', 'to' => '2026-10-31'])
        ->test(EmployeeAttendance::class, ['employeeId' => $employee->id])
        ->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-31')->assertSet('dayFilter', 'all');
});

test('each day tab contains only its category and an overlapping day appears twice', function (): void {
    $night = timeManagementPageEmployee('NIGHT', $this->branch);
    $friday = timeManagementPageEmployee('FRIDAY', $this->branch);
    $holiday = timeManagementPageEmployee('HOLIDAY', $this->branch);
    $both = timeManagementPageEmployee('BOTH', $this->branch);
    timeManagementPageDay($night, $this->branch, $this->policy, '2026-10-15');
    timeManagementPageDay($friday, $this->branch, $this->policy, '2026-10-16', ['shift_start' => '08:00:00']);
    timeManagementPageDay($holiday, $this->branch, $this->policy, '2026-10-19',
        ['shift_start' => '08:00:00', 'overtime_basis' => 'holiday']);
    timeManagementPageDay($both, $this->branch, $this->policy, '2026-10-16');

    Livewire::test(TimeManagementPage::class)->set('month', '2026-10')
        ->assertSee('NIGHT')->assertSee('BOTH')->assertDontSee('FRIDAY')->assertDontSee('HOLIDAY')
        ->call('showFriday')->assertSee('FRIDAY')->assertSee('BOTH')->assertDontSee('NIGHT')->assertDontSee('HOLIDAY')
        ->call('showHoliday')->assertSee('HOLIDAY')->assertDontSee('BOTH')->assertDontSee('FRIDAY')
        ->call('showTotals')->assertSee('NIGHT')->assertSee('FRIDAY')->assertSee('HOLIDAY')->assertSee('BOTH');

    Livewire::withQueryParams(['month' => '2026-10', 'tab' => 'friday'])
        ->test(TimeManagementPage::class)->assertSet('tab', 'friday')->assertSee('FRIDAY')->assertDontSee('NIGHT');
});

test('month branch and department select only matching report days', function (): void {
    $first = timeManagementPageEmployee('A001', $this->branch);
    $second = timeManagementPageEmployee('B002', $this->secondBranch, 'Finance');
    timeManagementPageDay($first, $this->branch, $this->policy, '2026-10-15');
    timeManagementPageDay($second, $this->secondBranch, $this->policy, '2026-10-16');
    timeManagementPageDay($first, $this->branch, $this->policy, '2026-09-15');

    Livewire::test(TimeManagementPage::class)->set('month', '2026-10')
        ->assertSee('A001')->assertSee('B002')
        ->set('branchId', (string) $this->branch->id)->assertSee('A001')->assertDontSee('B002')
        ->set('branchId', '')->set('department', 'Finance')->assertSee('B002')->assertDontSee('A001')
        ->set('month', '2026-09')->assertDontSee('B002');
});

test('export action queues the same filtered month in the UI locale', function (): void {
    $this->hr->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');
    Livewire::test(TimeManagementPage::class)->set('month', '2026-10')
        ->set('branchId', (string) $this->branch->id)->set('department', 'Finance')
        ->call('requestExport')->assertSet('exportQueued', true);

    $export = AttendanceExport::query()->sole();
    expect($export->kind)->toBe('time_management')->and($export->locale)->toBe('ar')
        ->and($export->filters['from'])->toBe('2026-10-01')
        ->and($export->filters['to'])->toBe('2026-10-31')
        ->and($export->filters['branch_ids'])->toBe([$this->branch->id])
        ->and($export->filters['departments'])->toBe(['Finance']);
});

test('page denies a non HR user and never shows another company', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, function (): void {
        $branch = Branch::factory()->create();
        $policy = Policy::factory()->create(['shift_minutes' => 480]);
        $employee = timeManagementPageEmployee('FOREIGN', $branch);
        timeManagementPageDay($employee, $branch, $policy, '2026-10-16');
    });
    Livewire::test(TimeManagementPage::class)->set('month', '2026-10')->assertDontSee('FOREIGN');

    $viewer = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff, 'is_active' => false]);
    actingInAdminTenant($this, $viewer, $this->company);
    Livewire::test(TimeManagementPage::class)->assertForbidden();
});

test('days paginate after fifty rows', function (): void {
    for ($i = 1; $i <= 51; $i++) {
        $employee = timeManagementPageEmployee(sprintf('PAGE%03d', $i), $this->branch);
        timeManagementPageDay($employee, $this->branch, $this->policy, '2026-10-16');
    }
    $page = Livewire::test(TimeManagementPage::class)->set('month', '2026-10');
    $page->assertSee('PAGE001')->assertDontSee('PAGE051')->call('gotoPage', 2, 'nightPage')
        ->assertSee('PAGE051')->assertDontSee('PAGE001')
        ->call('showFriday')->assertSee('PAGE001')->assertDontSee('PAGE051')
        ->call('gotoPage', 2, 'fridayPage')->assertSee('PAGE051')->assertDontSee('PAGE001')
        ->call('showNight')->assertSee('PAGE051')->assertDontSee('PAGE001');
    expect($page->instance()->daysPage($page->instance()->report()['night'], 'night')->total())->toBe(51);
});
