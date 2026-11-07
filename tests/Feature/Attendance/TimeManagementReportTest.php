<?php

use App\Enums\UserRole;
use App\Jobs\Attendance\GenerateAttendanceExportJob;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Attendance\Day;
use App\Models\Attendance\Policy;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\Reporting\AttendanceExports;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\TimeManagementReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use ZipArchive;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function timeManagementDay(Employee $employee, Branch $branch, Policy $policy, string $date, array $overrides = []): Day
{
    return Day::factory()->create(array_replace([
        'employee_id' => $employee->id, 'branch_id' => $branch->id, 'policy_id' => $policy->id,
        'work_date' => $date, 'status' => 'present', 'inputs' => ['work_pattern' => 'shift'],
        'shift_start' => '08:00:00', 'first_in' => $date.' 08:00:00',
        'last_out' => $date.' 16:00:00', 'presence_minutes' => 480,
        'overtime_minutes' => 0,
    ], $overrides));
}

test('time management selects worked shift nights Fridays and holidays once and totals their time', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, function (): void {
        $employee = Employee::factory()->create(['employee_code' => 'OTHER', 'work_pattern' => 'shift']);
        $branch = Branch::factory()->create();
        $policy = Policy::factory()->create();
        timeManagementDay($employee, $branch, $policy, '2026-10-16', ['shift_start' => '00:00:00']);
    });

    $company = Company::factory()->create();
    app(TenantContext::class)->runForCompany($company, function () use ($company): void {
        $branch = Branch::factory()->create();
        $policy = Policy::factory()->create(['shift_minutes' => 480]);
        $employee = Employee::factory()->create(['branch_id' => $branch->id, 'employee_code' => '0007',
            'work_pattern' => 'shift', 'hire_date' => '2020-01-01', 'department' => 'Operations']);
        $another = Employee::factory()->create(['branch_id' => $branch->id, 'employee_code' => '0008',
            'work_pattern' => 'shift', 'hire_date' => '2020-01-01']);

        timeManagementDay($employee, $branch, $policy, '2026-10-14', ['shift_start' => '00:00:00',
            'first_in' => '2026-10-13 23:00:00', 'last_out' => '2026-10-14 07:00:00']);
        timeManagementDay($employee, $branch, $policy, '2026-10-15', ['shift_start' => '00:00:00',
            'first_in' => '2026-10-15 23:00:00', 'last_out' => '2026-10-16 07:00:00',
            'overtime_minutes' => 60, 'overtime_basis' => 'after_end']);
        timeManagementDay($employee, $branch, $policy, '2026-10-16', ['shift_start' => '00:00:00',
            'status' => 'rest_day_worked', 'overtime_minutes' => 480, 'overtime_basis' => 'rest_day']);
        timeManagementDay($employee, $branch, $policy, '2026-10-19', ['overtime_basis' => 'holiday']);
        timeManagementDay($employee, $branch, $policy, '2026-10-20', ['status' => 'holiday_worked',
            'overtime_minutes' => 120]);
        timeManagementDay($another, $branch, $policy, '2026-10-23', ['status' => 'rest_day_worked',
            'last_out' => null, 'presence_minutes' => 90]);
        timeManagementDay($employee, $branch, $policy, '2026-10-21', ['overtime_minutes' => 60,
            'overtime_basis' => 'after_end']);
        timeManagementDay($employee, $branch, $policy, '2026-10-22', ['first_in' => null]);
        foreach (['office', 'security'] as $pattern) {
            $excluded = Employee::factory()->create(['branch_id' => $branch->id, 'work_pattern' => $pattern,
                'hire_date' => '2020-01-01']);
            timeManagementDay($excluded, $branch, $policy, '2026-10-16', ['inputs' => ['work_pattern' => $pattern]]);
        }

        $filters = ReportFilters::fromArray($company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
        $service = app(TimeManagementReport::class);
        $days = $service->days($filters);
        expect($days)->toHaveCount(6)
            ->and(array_column($days, 'date'))->toBe(['2026-10-14', '2026-10-15', '2026-10-16', '2026-10-19', '2026-10-20', '2026-10-23'])
            ->and($days[2]['night'])->toBeTrue()->and($days[2]['friday'])->toBeTrue()
            ->and($service->totals($days)[0]['night_count'])->toBe(3)
            ->and($service->totals($days)[0]['night_minutes'])->toBe(1440)
            ->and($service->totals($days)[0]['friday_count'])->toBe(1)
            ->and($service->totals($days)[0]['holiday_minutes'])->toBe(960);

        [$formatted, $totals] = app(ExportData::class)->timeManagement($filters);
        expect($formatted[0]['check_in'])->toBe('2026-10-13 23:00')
            ->and($formatted[1]['check_in'])->toBe('23:00')
            ->and($formatted[1]['check_out'])->toBe('2026-10-16 07:00')
            ->and($formatted[1]['shift'])->toBe('Night shift (00:00–08:00)')
            ->and($formatted[1]['total_time'])->toBe('8:00')
            ->and($formatted[1]['overtime'])->toBe('1:00')
            ->and($formatted[2]['type'])->toBe('Night shift · Friday')
            ->and($formatted[5]['check_out'])->toBe('')
            ->and($formatted[5]['total_time'])->toBe('1:30')
            ->and($formatted[5]['overtime'])->toBe('0:00')
            ->and($formatted[5]['note'])->toBe('Missing check out')
            ->and($totals[0]['night_total_time'])->toBe('24:00')
            ->and($totals[0]['total_overtime'])->toBe('11:00');

        expect($service->days(ReportFilters::fromArray($company, ['from' => '2026-10-01', 'to' => '2026-10-31',
            'employee_ids' => [$employee->id]])))->toHaveCount(5)
            ->and($service->days(ReportFilters::fromArray($company, ['from' => '2026-10-01', 'to' => '2026-10-31',
                'statuses' => ['holiday_worked']])))->toHaveCount(1)
            ->and($service->days(ReportFilters::fromArray($company, ['from' => '2026-10-01', 'to' => '2026-10-31',
                'work_patterns' => ['office']])))->toBe([]);
    });
});

test('time management appears in exports and writes two localized sheets with selected days', function (): void {
    Storage::fake('local');
    Queue::fake();
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create();
    $policy = Policy::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id, 'employee_code' => '0007',
        'work_pattern' => 'shift', 'hire_date' => '2020-01-01']);
    $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
    timeManagementDay($employee, $branch, $policy, '2026-10-16', ['status' => 'rest_day_worked']);
    timeManagementDay($employee, $branch, $policy, '2026-10-17', ['shift_start' => '00:00:00']);

    $this->actingAs($hr);
    Livewire::test(HrDashboard::class)->assertSee('Time Management');
    $export = app(AttendanceExports::class)->request($company, $hr, 'time_management',
        ['from' => '2026-10-16', 'to' => '2026-10-17', 'branch_ids' => [$branch->id]], 'ar');
    app()->call([new GenerateAttendanceExportJob($export->id), 'handle']);
    $export->refresh();

    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($export->file_path));
    $sheets = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        $rows = [];
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        $sheets[$sheet->getName()] = $rows;
    }
    $reader->close();
    expect(array_keys($sheets))->toBe(['الأيام', 'المجموع لكل موظف'])
        ->and($export->row_count)->toBe(2)
        ->and(collect($sheets['الأيام'])->pluck(0)->filter(fn ($value) => $value === '0007'))->toHaveCount(2)
        ->and($sheets['المجموع لكل موظف'][1][0])->toBe('0007')
        ->and($sheets['المجموع لكل موظف'][1][3])->toBe(1)
        ->and($sheets['المجموع لكل موظف'][1][4])->toBe('8:00')
        ->and($sheets['المجموع لكل موظف'][1][5])->toBe(1)
        ->and($sheets['المجموع لكل موظف'][1][6])->toBe('8:00')
        ->and($sheets['المجموع لكل موظف'][2][0])->toBe('الإجمالي')
        ->and($sheets['المجموع لكل موظف'][2][4])->toBe('8:00');
    $zip = new ZipArchive;
    $zip->open(Storage::disk('local')->path($export->file_path));
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('rightToLeft="1"')
        ->and($zip->getFromName('xl/worksheets/sheet2.xml'))->toContain('rightToLeft="1"');
    $zip->close();
});
