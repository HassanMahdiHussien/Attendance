<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\AttendancePunches;
use App\Livewire\Attendance\DashboardEmployees;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Attendance\Day;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\EmployeeFilterSearch;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 12:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('dashboard and employee attendance show the same current projection and preserve filters in drill down', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'work_pattern' => 'office']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
        'work_date' => '2026-10-21', 'status' => 'present', 'late_minutes' => 15]);
    Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
        'work_date' => '2026-10-22', 'status' => 'pending']);
    $filters = ReportFilters::fromArray($company, ['employee_ids' => [$employee->id]]);
    $total = app(AttendanceSummary::class)->totals($filters);
    expect($total['office_employees_assessed'])->toBe(1)
        ->and($total['days']['present'])->toBe(1)->and($total['days']['pending'])->toBe(1)
        ->and($total['minutes']['late']['gross'])->toBe(15);

    $this->actingAs($hr);
    Livewire::test(HrDashboard::class)->assertSet('from', '2026-10-01')->assertSet('to', '2026-10-23')
        ->assertSee('Attendance dashboard')->assertSee('Present days')->assertSee('Pending days')
        ->set('employeeId', (string) $employee->id)->call('apply')->assertHasNoErrors();
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id])
        ->assertSet('from', '2026-10-01')->assertSee('Current month')->assertSee('0:15')
        ->assertSee('Pending')->call('showTile', 'present')->assertSee('2026-10-21')
        ->assertDontSee('2026-10-22')->call('show', 'changes')->assertSee('Changes');
    Livewire::withQueryParams(['tile' => 'present', 'from' => '2026-10-01', 'to' => '2026-10-23'])
        ->test(DashboardEmployees::class)->assertSee($employee->name)->assertSee('Matching employees');
    expect(app(AttendanceSummary::class)->drillDown($filters, 'present'))->toBe([$employee->id]);
});

test('dashboard and nested employee section reject foreign tenant access on later requests', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $foreign = app(TenantContext::class)->runForCompany($b, fn () => Employee::factory()->create());
    $hr = User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::HrStaff]);
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    app(TenantContext::class)->setCompany($a);

    $this->actingAs($hr)->get(route('dashboard.company', ['company' => $b->id]))->assertNotFound();
    $this->get(route('employees.show', $foreign))->assertNotFound();
    Livewire::withQueryParams(['tile' => 'active_employees'])
        ->test(DashboardEmployees::class, ['company' => (string) $b->id])->assertNotFound();

    $this->actingAs($platform)->get(route('dashboard.company', ['company' => $b->id]))->assertOk()
        ->assertSee($b->name);
});

test('drill down keeps branch and period filters and quick periods use Baghdad dates', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $firstBranch = Branch::factory()->create(['name' => 'Hilla']);
    $secondBranch = Branch::factory()->create(['name' => 'Erbil']);
    $first = Employee::factory()->create(['name' => 'First Local', 'branch_id' => $firstBranch->id]);
    $second = Employee::factory()->create(['name' => 'Second Local', 'branch_id' => $secondBranch->id]);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    Day::factory()->create(['employee_id' => $first->id, 'branch_id' => $firstBranch->id,
        'work_date' => '2026-10-21', 'status' => 'present']);
    Day::factory()->create(['employee_id' => $second->id, 'branch_id' => $secondBranch->id,
        'work_date' => '2026-10-21', 'status' => 'present']);

    $this->actingAs($hr);
    Livewire::test(HrDashboard::class)->call('period', 'previous')
        ->assertSet('from', '2026-09-01')->assertSet('to', '2026-09-30');
    Livewire::withQueryParams(['tile' => 'present', 'branch_ids' => [$firstBranch->id],
        'from' => '2026-10-01', 'to' => '2026-10-23'])
        ->test(DashboardEmployees::class)->assertSee('First Local')->assertDontSee('Second Local')
        ->assertSee('Matching employees');
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $first->id])
        ->call('period', 'previous')->assertSet('from', '2026-09-01')
        ->set('to', '2026-10-21')->set('from', '2026-10-20')->call('apply')
        ->assertHasNoErrors()->assertSee('2026-10-21');
});

test('punch viewer opens on the linked employee and day', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create();
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $hr, $company);

    Livewire::withQueryParams(['employee' => (string) $employee->id, 'from' => '2026-10-21', 'to' => '2026-10-21'])
        ->test(AttendancePunches::class)->assertSet('employeeId', $employee->id)
        ->assertSet('from', '2026-10-21')->assertSet('to', '2026-10-21')
        ->call('clearEmployee')->set('employeeSearch', $employee->employee_code)
        ->assertSee($employee->employee_code)->call('chooseEmployee', $employee->id)
        ->assertSet('employeeId', $employee->id);
});

test('dashboard employee and manager filters search within the selected company', function (): void {
    $company = Company::factory()->create();
    $otherCompany = Company::factory()->create();
    $branch = app(TenantContext::class)->runForCompany($company, fn () => Branch::factory()->create(['name' => 'Hilla']));
    $employee = app(TenantContext::class)->runForCompany($company, fn () => Employee::factory()->create([
        'branch_id' => $branch->id, 'name' => 'Samir Local', 'name_en' => 'Samir Local', 'employee_code' => '00001234',
    ]));
    app(TenantContext::class)->runForCompany($otherCompany, fn () => Employee::factory()->create([
        'name' => 'Samir Foreign', 'name_en' => 'Samir Foreign', 'employee_code' => '00005678',
    ]));
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($hr);
    app(TenantContext::class)->setCompany($company);
    expect(collect(app(EmployeeFilterSearch::class)->find($platform, $company, 'samir'))->pluck('id')->all())
        ->toBe([$employee->id]);

    Livewire::test(HrDashboard::class)
        ->set('employeeSearch', 'samir')->assertSee('Samir Local')->assertSee('00001234')
        ->assertSee('Hilla')->assertDontSee('Samir Foreign')
        ->call('chooseEmployee', $employee->id)->assertSet('employeeId', (string) $employee->id)
        ->set('managerSearch', '00001234')->assertSee('Samir Local')
        ->call('chooseManager', $employee->id)->assertSet('managerId', (string) $employee->id)
        ->call('clearEmployee')->assertSet('employeeId', '')
        ->call('clearManager')->assertSet('managerId', '');
});

test('employee daily lines show clock times and capitalised English labels', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create();
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
        'work_date' => '2026-10-21', 'status' => 'present', 'first_in' => '2026-10-21 09:15:00',
        'last_out' => '2026-10-21 18:00:00', 'late_minutes' => 15]);
    $this->actingAs($hr);

    Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id])
        ->assertSee('09:15 / 18:00')->assertDontSee('2026-10-21 09:15:00')
        ->assertSee('Lateness: Gross')->assertDontSee('lateness: Gross');
});

test('an entitlement link opens the employee overtime ledger', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create();
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($hr);

    Livewire::withQueryParams(['attendance_tab' => 'overtime'])
        ->test(EmployeeAttendance::class, ['employeeId' => $employee->id])
        ->assertSet('tab', 'overtime')->assertSee('Overtime and compensatory days');
});
