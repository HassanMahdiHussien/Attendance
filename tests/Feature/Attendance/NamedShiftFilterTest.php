<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\DailyAttendance;
use App\Livewire\Attendance\DashboardEmployees;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Attendance\Day;
use App\Models\Attendance\Policy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\ShiftDisplay;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-22 12:00:00');
    app()->setLocale('en');
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create();
    $this->employee = Employee::factory()->create(['branch_id' => $this->branch->id,
        'work_pattern' => 'shift', 'hire_date' => '2020-01-01', 'name' => 'Local shift employee']);
    $this->office = Employee::factory()->create(['branch_id' => $this->branch->id,
        'work_pattern' => 'office', 'hire_date' => '2020-01-01', 'name' => 'Local office employee']);
    $this->policy = Policy::factory()->create(['effective_from' => '2026-10-01', 'version' => 1]);
    $this->hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->hr, $this->company);
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
    app()->setLocale('en');
});

test('a day schedule has the requested names and an unknown start has no name', function (): void {
    foreach ([
        ['shift', '00:00:00', 'night', 'Night shift'],
        ['shift', '08:00:00', 'shift_a', 'Shift A'],
        ['shift', '16:00:00', 'shift_b', 'Shift B'],
        ['office', null, 'office', 'Office'],
        ['security', null, 'security', 'Security'],
        ['shift', null, 'unresolved', 'Shift not determined'],
        ['shift', '12:00:00', null, null],
    ] as [$pattern, $start, $key, $name]) {
        expect(ShiftDisplay::key($pattern, $start))->toBe($key)
            ->and(ShiftDisplay::name($pattern, $start))->toBe($name);
    }
    app()->setLocale('ar');
    expect(ShiftDisplay::name('shift', '00:00:00'))->toBe('الوردية الليلية');
    app()->setLocale('en');
});

test('shift filters validate keys and omit an empty shift array from serialized filters', function (): void {
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-19', 'to' => '2026-10-21']);
    expect($filters->toArray())->not->toHaveKey('shifts');
    expect(fn () => ReportFilters::fromArray($this->company, [
        'from' => '2026-10-19', 'to' => '2026-10-21', 'shifts' => ['other'],
    ]))->toThrow(ValidationException::class);
});

test('shift totals, days, screens and tenants agree on the selected shift', function (): void {
    foreach ([['2026-10-19', '00:00:00', 60], ['2026-10-20', '08:00:00', 25],
        ['2026-10-21', '16:00:00', 40]] as [$date, $start, $late]) {
        Day::factory()->create(['employee_id' => $this->employee->id, 'branch_id' => $this->branch->id,
            'policy_id' => $this->policy->id, 'work_date' => $date, 'status' => 'present',
            'shift_start' => $start, 'inputs' => ['work_pattern' => 'shift'], 'late_minutes' => $late]);
    }
    Day::factory()->create(['employee_id' => $this->office->id, 'branch_id' => $this->branch->id,
        'policy_id' => $this->policy->id, 'work_date' => '2026-10-21', 'status' => 'present',
        'inputs' => ['work_pattern' => 'office'], 'late_minutes' => 90]);
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, function (): void {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $branch->id, 'work_pattern' => 'shift']);
        Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
            'work_date' => '2026-10-20', 'status' => 'present', 'shift_start' => '08:00:00',
            'inputs' => ['work_pattern' => 'shift'], 'late_minutes' => 500]);
    });
    $filters = ReportFilters::fromArray($this->company, [
        'from' => '2026-10-19', 'to' => '2026-10-21', 'shifts' => ['shift_a'],
    ]);
    $summary = app(AttendanceSummary::class);
    $days = iterator_to_array($summary->perDay($filters, $this->employee->id));
    expect($days)->toHaveCount(1)
        ->and($summary->totals($filters)['minutes']['late']['gross'])
        ->toBe(array_sum(array_map(fn (array $day): int => $day['minutes']['late']['gross'], $days)));
    expect($summary->drillDown($filters, 'present'))->toBe([$this->employee->id]);
    Livewire::test(DailyAttendance::class)->set('date', '2026-10-21')->set('shift', 'shift_b')
        ->assertSee('Local shift employee')->assertSee('Shift B')->assertDontSee('Local office employee');
    Livewire::test(DailyAttendance::class)->set('date', '2026-10-20')->set('shift', 'shift_a')
        ->assertSee('Local shift employee')->assertDontSee('Local office employee');
    Livewire::test(HrDashboard::class)->set('from', '2026-10-19')->set('to', '2026-10-21')
        ->set('shift', 'shift_a')->call('apply')->assertSee('Shift A');
    $url = Livewire::test(HrDashboard::class)->set('from', '2026-10-19')->set('to', '2026-10-21')
        ->set('shift', 'shift_a')->instance()->tileUrl('present');
    expect($url)->toContain('shifts%5B0%5D=shift_a');
    Livewire::withQueryParams(['tile' => 'present', 'from' => '2026-10-19', 'to' => '2026-10-21',
        'shifts' => ['shift_a']])->test(DashboardEmployees::class)->assertSee('Local shift employee')
        ->assertDontSee('Local office employee');
    $counts = ShiftDisplay::workedCounts($this->company->id, $this->employee->id,
        '2026-10-19', '2026-10-21');
    ksort($counts);
    expect($counts)->toBe(['night' => 1, 'shift_a' => 1, 'shift_b' => 1]);
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $this->employee->id])
        ->set('from', '2026-10-19')->set('to', '2026-10-21')->assertSee('Shift A')->assertSee('Night shift')
        ->assertSee('<bdi dir="ltr">1</bdi> day', escape: false);
});
