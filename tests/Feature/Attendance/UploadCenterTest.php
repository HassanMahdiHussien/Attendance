<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\UploadAttendance;
use App\Filament\Admin\Pages\Attendance\UploadCenter;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Jobs\Leave\ImportLeaveBalanceJob;
use App\Jobs\Leave\ImportLeaveJob;
use App\Models\Attendance\ImportBatch;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\User;
use App\Services\Leave\LeaveFile;
use App\Support\Access\AdminNav;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function centerAttendanceFile(string $code = '12345678', string $time = '08:00', string $name = 'Transaction_20261020084331.csv'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name,
        "Employee ID,First Name,Department,Date,Time,Punch State,Serial Number,Device Name,Data Sources\n"
        .$code.",Fake Employee,Operations,2026-10-20,$time,Check In,SER-1,Hilla_In_Face,Face\n");
}

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id, 'name' => 'Hilla']);
    Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'employee_code' => '12345678']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('recognised attendance has a summary and imports with one click', function (): void {
    $page = Livewire::test(UploadCenter::class)->set('incomingFiles', [centerAttendanceFile()])
        ->assertSee('2026-10-20')->assertSee('1 punches');
    $page->call('import')->assertSee('Imported')->assertSee('View batch');
    $batch = ImportBatch::query()->where('company_id', $this->company->id)->sole();
    expect($batch->coverage_branch_ids)->toBe([$this->branch->id])
        ->and($batch->mapping)->toContain('person_code');
});

test('more than half unknown codes need explicit confirmation', function (): void {
    $file = UploadedFile::fake()->createWithContent('Transaction_20261020084331.csv',
        "Employee ID,First Name,Department,Date,Time,Punch State,Serial Number,Device Name,Data Sources\n"
        ."99999998,Fake A,Operations,2026-10-20,08:00,Check In,SER-1,Hilla_In_Face,Face\n"
        ."99999999,Fake B,Operations,2026-10-20,08:01,Check In,SER-1,Hilla_In_Face,Face\n"
        ."12345678,Fake C,Operations,2026-10-20,08:02,Check In,SER-1,Hilla_In_Face,Face\n");
    $page = Livewire::test(UploadCenter::class)->set('incomingFiles', [$file])->assertSee('Is this the right file?');
    $page->call('import');
    expect(ImportBatch::count())->toBe(0);
    $page->set('rows.0.confirmed', true)->call('import');
    expect(ImportBatch::count())->toBe(1);
});

test('already imported files stay out of the import set', function (): void {
    $file = centerAttendanceFile();
    Livewire::test(UploadCenter::class)->set('incomingFiles', [$file])->call('import');
    Livewire::test(UploadCenter::class)->set('incomingFiles', [centerAttendanceFile()])
        ->assertSee('already imported')->call('import');
    expect(ImportBatch::count())->toBe(1);
});

test('an unseen attendance column must be matched before import', function (): void {
    $file = UploadedFile::fake()->createWithContent('Transaction_20261020084331.csv',
        "Employee ID,Given Name,Department,Date,Time,Punch State,Serial Number,Device Name,Data Sources\n"
        ."12345678,Fake Employee,Operations,2026-10-20,08:00,Check In,SER-1,Hilla_In_Face,Face\n");
    $page = Livewire::test(UploadCenter::class)->set('incomingFiles', [$file])->assertSee('Match the columns');
    $page->call('import');
    expect(ImportBatch::count())->toBe(0);
    $page->set('rows.0.mapping.1', 'person_name')->call('refreshRow', 0)->call('import');
    expect(ImportBatch::count())->toBe(1);
});

test('employee data is committed before attendance is previewed for import', function (): void {
    $employees = UploadedFile::fake()->createWithContent('HC employees.csv',
        "employee_id,name,branch\n99999999,Fake New,Hilla\n");
    $attendance = centerAttendanceFile('99999999');
    Livewire::test(UploadCenter::class)->set('incomingFiles', [$attendance, $employees])->call('import');
    expect(EmployeeImportBatch::count())->toBe(1)->and(ImportBatch::count())->toBe(1);
});

test('queued imports are pushed balance then leave then attendance', function (): void {
    Queue::fake();
    $employees = UploadedFile::fake()->createWithContent('HC employees.csv',
        "employee_id,name,branch\n99999999,Fake New,Hilla\n");
    $balance = UploadedFile::fake()->createWithContent('Annual Balance.csv',
        "Pers.No.,Total Remaining Annual Leave Days,ANNUAL LEAVE DAYS EMPLOYEE IS/TO BE ENTITELED IN 2026\n99999999,15,20\n");
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, LeaveFile::HEADERS);
    fputcsv($stream, ['FORM-1', '99999999', 'Fake New', 'Fake Approver', 'Hilla', 'Operations',
        'Approved', 'Annual Leave', '', '10/20/2026', '10/20/2026', '1', '', 'Fake Creator', '10/1/2026', 'Fake Manager', '']);
    rewind($stream);
    $leave = UploadedFile::fake()->createWithContent('IRAQ All Leave REPORT-2026-10-22-10-00-00.csv', stream_get_contents($stream));
    fclose($stream);

    $page = Livewire::test(UploadCenter::class)->set('incomingFiles', [centerAttendanceFile('99999999'), $leave, $balance, $employees])
        ->set('rows.2.settings.date', today('Asia/Baghdad')->toDateString())->call('refreshRow', 2)->call('import');
    $page->assertSee('View batch');
    expect(EmployeeImportBatch::count())->toBe(1)->and(ImportBatch::count())->toBe(1);
    expect(collect(Queue::pushedJobs())->flatten(1)->map(fn (array $job): string => $job['job']::class)
        ->filter(fn (string $class): bool => in_array($class, [ImportLeaveBalanceJob::class, ImportLeaveJob::class, ImportTransactionsJob::class], true))->values()->all())
        ->toBe([ImportLeaveBalanceJob::class, ImportLeaveJob::class, ImportTransactionsJob::class]);
});

test('a failed first file stops later ready files', function (): void {
    $page = Livewire::test(UploadCenter::class)->set('incomingFiles', [
        centerAttendanceFile(), centerAttendanceFile('12345678', '08:01', 'Transaction_20261020084332.csv'),
    ]);
    $page->set('rows.0.settings.from', 'invalid')->call('import')->assertSee('Import stopped');
    expect(ImportBatch::count())->toBe(0);
});

test('the upload page rejects another tenant for this user', function (): void {
    $other = Company::factory()->create();
    Filament::setTenant($other);
    Livewire::test(UploadCenter::class)->assertForbidden();
});

test('a preview from one tenant cannot be committed after switching tenants', function (): void {
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    actingInAdminTenant($this, $platform, $this->company);
    $page = Livewire::test(UploadCenter::class)->set('incomingFiles', [centerAttendanceFile()]);
    $other = Company::factory()->create();
    Filament::setTenant($other);
    app(TenantContext::class)->setCompany($other);
    $page->call('import');
    expect(ImportBatch::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('an employee import permission denial keeps that kind out of preview and import', function (): void {
    Gate::define('importEmployees', fn (): bool => false);
    $employees = UploadedFile::fake()->createWithContent('HC employees.csv',
        "employee_id,name,branch\n99999999,Fake New,Hilla\n");
    Livewire::test(UploadCenter::class)->set('incomingFiles', [$employees])->assertSee('Unknown file')->call('import');
    expect(EmployeeImportBatch::count())->toBe(0);
});

test('navigation lists Upload first and advanced attendance remains reachable', function (): void {
    expect(AdminNav::uploadItems($this->actor)[0]['label'])->toBe('Upload');
    expect(array_column(AdminNav::attendanceItems($this->actor), 'label'))->not->toContain(__('messages.attendance_ui.upload_title'));
    Livewire::test(UploadCenter::class)->assertSee('Advanced upload')->assertSee('Attendance');
    Livewire::test(UploadAttendance::class)->assertSee('Back to Upload');
});
