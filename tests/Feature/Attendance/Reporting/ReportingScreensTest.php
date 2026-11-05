<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\AttendancePunches;
use App\Livewire\Attendance\DashboardEmployees;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Attendance\Day;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\Policy;
use App\Models\Attendance\ProcessingRun;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\User;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\EmployeeFilterSearch;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Queue;
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
        ->assertSee('Attendance dashboard')->assertSee('Month figures')->assertSee('Pending days')
        ->set('employeeId', (string) $employee->id)->call('apply')->assertHasNoErrors();
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id])
        ->assertSet('from', '2026-10-01')->assertSet('dayFilter', 'review')
        ->assertSee('Current month')->assertSee('15 min')
        ->assertSee('Needs review (1)')->call('setDayFilter', 'all')->assertSee('All days (2)')
        ->call('showTile', 'present')->assertSet('dayFilter', 'all')->assertSee('21 October')
        ->assertDontSee('22 October')->call('show', 'changes')->assertSee('Changes');
    Livewire::withQueryParams(['tile' => 'present', 'from' => '2026-10-01', 'to' => '2026-10-23'])
        ->test(DashboardEmployees::class)->assertSee($employee->name)->assertSee('Matching employees');
    expect(app(AttendanceSummary::class)->drillDown($filters, 'present'))->toBe([$employee->id]);
});

test('home shows one primary task action and an empty state in both languages', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($hr);
    Livewire::test(HrDashboard::class)->assertSee('Nothing needs you right now.')
        ->assertSee('Upload a newer file');
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
        'work_date' => '2026-10-21', 'status' => 'absent']);
    $page = Livewire::test(HrDashboard::class);
    expect($page->html())->toMatch('/Review absences<\/a>/')
        ->toMatch('/ui-button-primary[^>]*>Review absences<\/a>/');
    app()->setLocale('ar');
    Livewire::test(HrDashboard::class)->assertSee('بانتظار إجراء منك')->assertSee('راجع الغياب')
        ->assertSee('أحدث الملفات');
    app()->setLocale('en');
});

test('employee daily record shows out time on the row and interval in details', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['name' => 'Mira Example', 'employee_code' => 'TEST0081',
        'branch_id' => $branch->id, 'work_pattern' => 'office']);
    $policy = Policy::factory()->create();
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
        'policy_id' => $policy->id, 'work_date' => '2026-10-21', 'status' => 'present',
        'inputs' => ['work_pattern' => 'office'], 'first_in' => '2026-10-21 08:29:00',
        'last_out' => '2026-10-21 17:53:00', 'elapsed_minutes' => 564, 'presence_minutes' => 499,
        'exit_minutes' => 65, 'short_minutes' => 0,
        'exits' => [['from' => '2026-10-21 12:50:00', 'to' => '2026-10-21 13:55:00', 'minutes' => 65]]]);

    $this->actingAs($hr);
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id])->call('setDayFilter', 'all')
        ->assertSee('Out')->assertSee('1 h 5 min')->assertSee('5 min')->assertSee('12:50')->assertSee('13:55');
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
        ->assertHasNoErrors()->call('setDayFilter', 'all')->assertSee('21 October');
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
    $employee = Employee::factory()->create(['work_pattern' => 'office']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
        'work_date' => '2026-10-21', 'status' => 'present', 'first_in' => '2026-10-21 09:15:00',
        'last_out' => '2026-10-21 18:00:00', 'late_minutes' => 15]);
    $this->actingAs($hr);

    Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id])
        ->assertSee('· Office ·')->assertSee('09:15')->assertSee('18:00')->assertDontSee('2026-10-21 09:15:00')
        ->assertSee('Late 15 min')->assertSee('Lateness: Gross')->assertSee('15 min')->assertDontSee('lateness: Gross');
});

test('daily review excludes fully covered absence and pending while all days keeps both', function (): void {
    // The reconciler queues a recalculation that would turn these uncovered test days back to pending.
    Queue::fake();
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create(['work_pattern' => 'office', 'hire_date' => '2020-01-01']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $type = LeaveType::factory()->create(['name' => 'Annual Leave', 'normalized_key' => 'annual leave|', 'covers' => true, 'is_mapped' => true]);
    foreach (['2026-10-20' => 'absent', '2026-10-21' => 'absent', '2026-10-22' => 'pending'] as $date => $status) {
        Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
            'work_date' => $date, 'status' => $status]);
    }
    LeaveRequest::factory()->create(['employee_id' => $employee->id, 'owner_code' => $employee->employee_code,
        'leave_type_id' => $type->id, 'status' => 'approved', 'start_date' => '2026-10-21',
        'end_date' => '2026-10-21', 'duration_days' => '1.0']);
    app(Reconciler::class)->run($company, [$employee->id], 'report_test');
    $this->actingAs($hr);

    Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id])
        ->assertSet('dayFilter', 'review')->assertSee('Needs review (1)')
        ->assertSee('Full day absent, no leave')->assertDontSee('Absence covered by leave')
        ->assertDontSee('This day is pending and is not a completed absence.')
        ->call('setDayFilter', 'all')->assertSee('All days (3)')
        ->assertSee('Absence covered by leave')->assertSee('This day is pending and is not a completed absence.');

    $tile = Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id]);
    expect($tile->html())->toMatch('/Absent without leave.*?<bdi dir="auto">1<\/bdi>/s');
    $tile->call('showTile', 'absent_uncovered')->assertSet('selectedTile', 'absent_uncovered')
        ->assertSee('20 October')->assertDontSee('21 October')->assertDontSee('22 October');
    $filters = ReportFilters::fromArray($company, ['employee_ids' => [$employee->id]]);
    expect(ReportTiles::fromTotals(app(AttendanceSummary::class)->totals($filters))['absent_uncovered'])->toBe(1)
        ->and(app(AttendanceSummary::class)->drillDown($filters, 'absent_uncovered'))->toBe([$employee->id])
        ->and(iterator_to_array(app(AttendanceSummary::class)->drillDownDayIds($filters, 'absent_uncovered')))
        ->toBe([Day::query()->where('work_date', '2026-10-20')->where('employee_id', $employee->id)->value('id')]);
    Livewire::withQueryParams(['tile' => 'absent_uncovered', 'from' => '2026-10-01', 'to' => '2026-10-31'])->test(DashboardEmployees::class)
        ->assertSee($employee->name)->assertSee('20 Oct')->assertDontSee('21 Oct')
        ->assertSee('Open an employee to see these days and their punches.')
        ->assertSeeHtml('attendance_tile=absent_uncovered');
});

test('employee list shows each employee figure and days, biggest first', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $few = Employee::factory()->create(['name' => 'Aaa One Day', 'work_pattern' => 'office', 'hire_date' => '2020-01-01']);
    $many = Employee::factory()->create(['name' => 'Zzz Three Days', 'work_pattern' => 'office', 'hire_date' => '2020-01-01']);
    foreach ([[$few, '2026-10-20'], [$many, '2026-10-20'], [$many, '2026-10-21'], [$many, '2026-10-22']] as [$employee, $date]) {
        Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
            'work_date' => $date, 'status' => 'missing_punch']);
    }
    $this->actingAs(User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]));
    $filters = ReportFilters::fromArray($company, ['from' => '2026-10-01', 'to' => '2026-10-31']);

    expect(app(AttendanceSummary::class)->drillDownRows($filters, 'missing_punch')[$many->id]['value'])->toBe(3);
    Livewire::withQueryParams(['tile' => 'missing_punch', 'from' => '2026-10-01', 'to' => '2026-10-31'])->test(DashboardEmployees::class)
        ->assertSeeInOrder(['Zzz Three Days', '>3<', '20 Oct', '21 Oct', '22 Oct', 'Aaa One Day', '>1<', '20 Oct'], false);
});

test('late and early tile opens both kinds of days', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create(['work_pattern' => 'office']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
        'work_date' => '2026-10-20', 'status' => 'present', 'late_minutes' => 15]);
    Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
        'work_date' => '2026-10-21', 'status' => 'present', 'early_minutes' => 30]);
    $this->actingAs($hr);

    $tile = Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id]);
    expect($tile->html())->toMatch('/Late and early leaving.*?<bdi dir="auto">45 min<\/bdi>/s');
    $tile->call('showTile', 'late_early')->assertSet('selectedTile', 'late_early')
        ->assertSee('20 October')->assertSee('21 October');
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

test('employee attendance dates the last update by the upload, not the older recalculation', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create();
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    $batch = ImportBatch::factory()->create();
    ImportBatchEvent::create(['company_id' => $company->id, 'batch_id' => $batch->id,
        'sequence' => 1, 'state' => 'completed', 'created_at' => '2026-10-23 11:00:00']);
    ProcessingRun::factory()->create(['kind' => 'days', 'finished_at' => '2026-10-20 10:00:00']);
    $this->actingAs($hr);

    Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id])
        ->assertSee('2026-10-23 11:00:00')->assertDontSee('2026-10-20 10:00:00')
        ->assertSee('Results are updating');
});

test('a tile keeps an Arabic duration in reading order', function (): void {
    $html = Blade::render('<x-ui.tile label="Late" value="75 س 19 د" />');

    expect($html)->toContain('<bdi dir="auto">75 س 19 د</bdi>')->not->toContain('dir="ltr"');
});
