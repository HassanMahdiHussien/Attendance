<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\AttendanceExportsPage;
use App\Jobs\Attendance\GenerateAttendanceExportJob;
use App\Livewire\Attendance\EmployeeAttendance;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendanceExportEvent;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\Attendance\Meal;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\User;
use App\Services\Attendance\Reporting\AttendanceExports;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ExportKinds;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use ZipArchive;

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-23 12:00:00');
    Storage::fake('local');
    Queue::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
    App::setLocale('en');
});

function exportFixture(): array
{
    $company = Company::factory()->create();

    return app(TenantContext::class)->runForCompany($company, function () use ($company): array {
        $branch = Branch::factory()->create();
        $employee = Employee::factory()->create(['branch_id' => $branch->id, 'employee_code' => '00001234',
            'hire_date' => '2020-01-01', 'work_pattern' => 'office']);
        $hr = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::HrStaff]);
        Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
            'work_date' => '2026-10-21', 'status' => 'present', 'late_minutes' => 15]);
        Day::factory()->create(['employee_id' => $employee->id, 'branch_id' => $branch->id,
            'work_date' => '2026-10-22', 'status' => 'pending']);

        return compact('company', 'branch', 'employee', 'hr');
    });
}

function runAttendanceExport(int $id): void
{
    app()->call([new GenerateAttendanceExportJob($id), 'handle']);
}

function attendanceExportRows(AttendanceExport $export): array
{
    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($export->file_path));
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();

    return $rows;
}

test('summary, dashboard, employee tile, Excel totals and PDF data agree for the same period', function (): void {
    $fixture = exportFixture();
    $company = $fixture['company'];
    $employee = $fixture['employee'];
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->setCompany($company);
    $filters = ReportFilters::fromArray($company, ['from' => '2026-10-01', 'to' => '2026-10-23',
        'employee_ids' => [$employee->id]]);
    $totals = app(AttendanceSummary::class)->totals($filters);
    Livewire::test(HrDashboard::class)->set('employeeId', (string) $employee->id)->call('apply')
        ->assertSee('0:15')->assertSee('Present days');
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $employee->id])->assertSee('0:15');

    $exports = app(AttendanceExports::class);
    $excel = $exports->request($company, $fixture['hr'], 'employee_statement_xlsx', $filters->toArray(), 'en');
    runAttendanceExport($excel->id);
    $excel->refresh();
    $rows = attendanceExportRows($excel);
    $total = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === 'Total');
    $detailLate = collect($rows)->filter(fn (array $row): bool => ($row[0] ?? null) === '00001234')
        ->sum(fn (array $row): int => (int) $row[7]);
    expect($totals['minutes']['late']['gross'])->toBe(15)
        ->and($excel->row_count)->toBe(2)
        ->and(collect($rows)->pluck(0))->toContain('00001234')
        ->and(collect($rows)->pluck(2))->toContain('2026-10-21')
        ->and((int) $total[7])->toBe(15)
        ->and((int) $total[7])->toBe($detailLate)
        ->and((int) $total[10])->toBe(15);

    $pdf = AttendanceExport::factory()->create(['requested_by' => $fixture['hr']->id,
        'kind' => 'statement_pdf', 'filters' => $filters->toArray(), 'file_name' => 'statement.pdf']);
    $data = app(ExportData::class)->statement($pdf, $company, $filters);
    expect($data['totals']['minutes'])->toBe($totals['minutes'])
        ->and($data['totals']['days'])->toBe($totals['days'])
        ->and($data['days'][0]['late'])->toBe(15);
    foreach (['en', 'ar'] as $locale) {
        App::setLocale($locale);
        $localized = app(ExportData::class)->statement($pdf, $company, $filters);
        $html = view('attendance.statement', $localized)->render();
        expect($html)->toContain('00001234')->toContain('2026-10-21')
            ->toContain($locale === 'ar' ? 'dir="rtl"' : 'dir="ltr"')
            ->toContain($locale === 'ar' ? 'كشف حضور الموظف' : 'Employee attendance statement');
    }
});

test('each Excel kind writes a labelled private file with a detail total', function (): void {
    $fixture = exportFixture();
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->setCompany($fixture['company']);
    Meal::factory()->create(['employee_id' => $fixture['employee']->id, 'meal_date' => '2026-10-21']);
    DataIssue::factory()->create(['type' => 'device_outage', 'subject_type' => 'branch_date',
        'subject_key' => 'branch:'.$fixture['branch']->id.':2026-10-21']);
    foreach (array_diff(ExportKinds::ALL, ['statement_pdf']) as $kind) {
        $input = ['from' => '2026-10-01', 'to' => '2026-10-23'];
        if ($kind === 'employee_statement_xlsx') {
            $input['employee_ids'] = [$fixture['employee']->id];
        }
        $export = app(AttendanceExports::class)->request($fixture['company'], $fixture['hr'], $kind, $input, 'ar');
        runAttendanceExport($export->id);
        $export->refresh();
        $rows = attendanceExportRows($export);
        expect($export->events()->latest('sequence')->value('state'))->toBe('completed')
            ->and($rows[0][0])->toBe('التقرير')
            ->and(collect($rows)->pluck(0))->toContain('الإجمالي');
        $archive = new ZipArchive;
        $archive->open(Storage::disk('local')->path($export->file_path));
        expect($archive->getFromName('xl/worksheets/sheet1.xml'))->toContain('rightToLeft="1"');
        $archive->close();
    }
});

test('retry adds one running event and a completed job does not regenerate rows', function (): void {
    $fixture = exportFixture();
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->setCompany($fixture['company']);
    $export = AttendanceExport::factory()->create(['requested_by' => $fixture['hr']->id,
        'filters' => ['from' => '2026-10-01', 'to' => '2026-10-23', 'employee_ids' => [$fixture['employee']->id]]]);
    $path = 'attendance-exports/'.$export->id.'.xlsx';
    Storage::disk('local')->put($path, 'stale file');
    $export->update(['file_path' => $path]);
    AttendanceExportEvent::factory()->create(['export_id' => $export->id, 'state' => 'failed']);
    app(AttendanceExports::class)->retry($export, $fixture['hr']);
    Queue::assertPushed(GenerateAttendanceExportJob::class);
    runAttendanceExport($export->id);
    runAttendanceExport($export->id);
    expect($export->fresh()->row_count)->toBe(2)
        ->and($export->fresh()->file_path)->toBe($path)
        ->and(substr(Storage::disk('local')->get($path), 0, 2))->toBe('PK')
        ->and($export->events()->orderBy('sequence')->pluck('state')->all())
        ->toBe(['failed', 'queued', 'running', 'completed']);
});

test('leave and credit report shows the source request and applied credit without doubling its totals', function (): void {
    $fixture = exportFixture();
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->setCompany($fixture['company']);
    Day::factory()->create(['employee_id' => $fixture['employee']->id, 'branch_id' => $fixture['branch']->id,
        'work_date' => '2026-10-20', 'status' => 'present', 'early_minutes' => 180]);
    $type = LeaveType::factory()->create(['name' => 'Annual Leave', 'normalized_key' => 'annual leave|',
        'covers' => true, 'is_mapped' => true]);
    LeaveRequest::factory()->create(['employee_id' => $fixture['employee']->id,
        'owner_code' => $fixture['employee']->employee_code, 'leave_type_id' => $type->id,
        'external_id' => 'FORM-EXPORT-1', 'status' => 'approved',
        'start_date' => '2026-10-20', 'end_date' => '2026-10-20', 'duration_days' => '0.5']);
    app(Reconciler::class)->run($fixture['company'], [$fixture['employee']->id], 'export_test');
    $export = app(AttendanceExports::class)->request($fixture['company'], $fixture['hr'], 'leave_and_credit',
        ['from' => '2026-10-20', 'to' => '2026-10-21', 'employee_ids' => [$fixture['employee']->id]], 'en');
    runAttendanceExport($export->id);
    $rows = attendanceExportRows($export->fresh());
    $total = collect($rows)->first(fn (array $row): bool => ($row[0] ?? null) === 'Total');
    expect((int) $total[8])->toBe(60)
        ->and((int) $total[9])->toBe(15)
        ->and((int) $total[10])->toBe(45)
        ->and((int) $total[11])->toBe(15)
        ->and(collect($rows)->pluck(12))->toContain('FORM-EXPORT-1');
});

test('download is audited and refuses another company before resolving its export', function (): void {
    $a = exportFixture();
    $b = exportFixture();
    $foreign = app(TenantContext::class)->runForCompany($b['company'], function () use ($b): AttendanceExport {
        Storage::disk('local')->put('attendance-exports/foreign.xlsx', 'company-b');

        return AttendanceExport::factory()->create(['requested_by' => $b['hr']->id,
            'file_path' => 'attendance-exports/foreign.xlsx']);
    });
    $this->actingAs($a['hr']);
    app(TenantContext::class)->setCompany($a['company']);
    $this->get(route('attendance.exports.file', ['company' => $b['company']->id, 'export' => $foreign->id]))->assertNotFound();
    $this->get(route('attendance.exports.file', ['company' => $a['company']->id, 'export' => $foreign->id]))->assertNotFound();
    $this->actingAs($b['hr']);
    app(TenantContext::class)->setCompany($b['company']);
    $this->get(route('attendance.exports.file', ['company' => $b['company']->id, 'export' => $foreign->id]))->assertOk();
    expect(AuditLog::query()->where('company_id', $b['company']->id)
        ->where('action', 'attendance.export.downloaded')->count())->toBe(1);
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($platform);
    $this->get(route('attendance.exports.file', ['company' => $b['company']->id, 'export' => $foreign->id]))->assertOk();
});

test('an export request cannot carry another company employee in its filters', function (): void {
    $a = exportFixture();
    $b = exportFixture();
    $this->actingAs($a['hr']);
    app(TenantContext::class)->setCompany($a['company']);
    expect(fn () => app(AttendanceExports::class)->request($a['company'], $a['hr'], 'employee_statement_xlsx',
        ['from' => '2026-10-01', 'to' => '2026-10-23', 'employee_ids' => [$b['employee']->id]], 'en'))
        ->toThrow(ValidationException::class);
    expect(AttendanceExport::query()->count())->toBe(0);
});

test('cleanup removes only expired files and the missing Chrome path records a failure', function (): void {
    $fixture = exportFixture();
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->setCompany($fixture['company']);
    Storage::disk('local')->put('attendance-exports/old.xlsx', 'old');
    $old = AttendanceExport::factory()->create(['file_path' => 'attendance-exports/old.xlsx']);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    AttendanceExportEvent::factory()->create(['export_id' => $old->id, 'state' => 'completed']);
    expect(app(AttendanceExports::class)->cleanup())->toBe(1)
        ->and(Storage::disk('local')->exists('attendance-exports/old.xlsx'))->toBeFalse()
        ->and($old->fresh()->file_path)->toBeNull()
        ->and($old->events()->latest('sequence')->value('state'))->toBe('file_deleted');

    config()->set('attendance.pdf.chrome_path', '/missing/chrome-headless-shell');
    $pdf = AttendanceExport::factory()->create(['kind' => 'statement_pdf',
        'filters' => ['from' => '2026-10-01', 'to' => '2026-10-23', 'employee_ids' => [$fixture['employee']->id]]]);
    AttendanceExportEvent::factory()->create(['export_id' => $pdf->id]);
    expect(fn () => runAttendanceExport($pdf->id))->toThrow(RuntimeException::class, 'chrome_missing');
    expect($pdf->events()->latest('sequence')->value('error_code'))->toBe('chrome_missing');
});

test('the dashboard and employee actions queue scoped exports and the export page lists them', function (): void {
    $fixture = exportFixture();
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->setCompany($fixture['company']);
    Livewire::test(HrDashboard::class)->set('exportKind', 'absence')->call('requestExport')
        ->assertHasNoErrors()->assertSee('Export queued');
    Livewire::test(EmployeeAttendance::class, ['employeeId' => $fixture['employee']->id])
        ->set('exportKind', 'statement_pdf')->set('exportLocale', 'ar')->call('requestExport')
        ->assertHasNoErrors()->assertSee('Export queued');
    expect(AttendanceExport::query()->count())->toBe(2)
        ->and(AttendanceExport::query()->where('kind', 'statement_pdf')->first()->filters['employee_ids'])
        ->toBe([$fixture['employee']->id]);
    actingInAdminTenant($this, $fixture['hr'], $fixture['company']);
    Livewire::test(AttendanceExportsPage::class)->assertSee('Absence')
        ->assertSee('Employee attendance statement (PDF)');
});

test('platform administrator requests a report through an explicit company dashboard', function (): void {
    $fixture = exportFixture();
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $this->actingAs($platform);
    app(TenantContext::class)->setCompany($fixture['company']);
    Livewire::test(HrDashboard::class, ['company' => (string) $fixture['company']->id])
        ->set('exportKind', 'missing_punches')->call('requestExport')->assertHasNoErrors();
    expect(AttendanceExport::query()->first()->requested_by)->toBe($platform->id);
});

test('Chrome renders English and Arabic PDF statements when installed', function (): void {
    if (! is_file(config('attendance.pdf.chrome_path'))) {
        $this->markTestSkipped('Chrome headless shell is not installed.');
    }
    $fixture = exportFixture();
    $this->actingAs($fixture['hr']);
    app(TenantContext::class)->setCompany($fixture['company']);
    foreach (['en', 'ar'] as $locale) {
        $export = app(AttendanceExports::class)->request($fixture['company'], $fixture['hr'], 'statement_pdf',
            ['from' => '2026-10-01', 'to' => '2026-10-23', 'employee_ids' => [$fixture['employee']->id]], $locale);
        runAttendanceExport($export->id);
        $export->refresh();
        expect($export->events()->latest('sequence')->value('state'))->toBe('completed')
            ->and(Storage::disk('local')->exists($export->file_path))->toBeTrue()
            ->and(substr(Storage::disk('local')->get($export->file_path), 0, 4))->toBe('%PDF');
    }
});
