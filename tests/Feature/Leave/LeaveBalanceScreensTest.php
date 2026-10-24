<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Leave\LeaveBalanceBatches;
use App\Filament\Admin\Pages\Leave\UploadLeaveBalance;
use App\Jobs\Leave\ImportLeaveBalanceJob;
use App\Livewire\Attendance\DashboardEmployees;
use App\Models\Attendance\AttendanceExport;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceBatchEvent;
use App\Models\User;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Leave\LeaveBalanceFile;
use App\Services\Leave\LeaveBalanceImport;
use App\Services\Leave\LeaveBalances;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function balanceUiCsv(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('balance.csv',
        "Pers.No.,Total Remaining Annual Leave Days,REMAINING FROM 2025,YTD Act Used (Actual)\n12345678,3.5,1.5,2\n99999999,2,0,0\n12345678,5,0,0\n");
}

beforeEach(function (): void {
    Queue::fake();
    Filament::setTenant(null);
    $this->company = Company::factory()->create();
    $this->other = Company::factory()->create();
    $this->foreignBatch = app(TenantContext::class)->runForCompany($this->other, function (): BalanceBatch {
        Employee::factory()->create();
        $batch = BalanceBatch::factory()->create();
        BalanceBatchEvent::factory()->create(['batch_id' => $batch->id, 'sequence' => 1, 'state' => 'completed']);

        return $batch;
    });
    app(TenantContext::class)->setCompany($this->company);
    $this->employee = Employee::factory()->create(['employee_code' => '12345678']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('HR previews and imports a balance with skipped rows visible after processing', function (): void {
    config(['cache.default' => 'database']);
    Livewire::test(UploadLeaveBalance::class)->set('sourceFile', balanceUiCsv())
        ->call('prepare')->assertSet('step', 'mapping')
        ->set('balanceDate', '2026-10-20')->call('preview')->assertSet('step', 'preview')
        ->assertSee('Unknown codes')->assertSee('Duplicates')
        ->call('commit')->assertSet('step', 'progress');
    $batch = BalanceBatch::query()->where('company_id', $this->company->id)->sole();
    Queue::assertPushed(ImportLeaveBalanceJob::class);
    app()->call([new ImportLeaveBalanceJob($batch->id), 'handle']);

    Livewire::test(LeaveBalanceBatches::class)->call('showBatch', $batch->id)
        ->assertSee('Skipped rows')->assertSee('99999999')->assertSee('Duplicate');
    expect(app(LeaveBalances::class)->current($this->employee)?->remaining_days)->toBe('3.5000');
});

test('balance upload blocks missing mapping and future dates before creating a batch', function (): void {
    Livewire::test(UploadLeaveBalance::class)->set('sourceFile', balanceUiCsv())->call('prepare')
        ->set('mapping', [0 => 'employee_code'])->call('preview')->assertHasErrors('mapping');
    Livewire::test(UploadLeaveBalance::class)->set('sourceFile', balanceUiCsv())->call('prepare')
        ->set('balanceDate', '2099-01-01')->call('preview')->assertHasErrors('balanceDate');

    expect(BalanceBatch::query()->where('company_id', $this->company->id)->count())->toBe(0);
});

test('balance pages and file download stay within the selected company', function (): void {
    Storage::fake('local');
    Storage::disk('local')->put($this->foreignBatch->file_path, 'foreign original');
    Livewire::test(LeaveBalanceBatches::class)->call('showBatch', $this->foreignBatch->id)->assertNotFound();
    $this->get(route('leave.balance.file', ['company' => $this->other->id, 'balanceBatch' => $this->foreignBatch->id]))->assertNotFound();

    $batch = BalanceBatch::factory()->create(['company_id' => $this->company->id, 'uploaded_by' => $this->actor->id,
        'file_path' => 'leave-balances/own.csv', 'file_name' => 'own.csv']);
    Storage::disk('local')->put($batch->file_path, 'own original');
    $this->get(route('leave.balance.file', ['company' => $this->company->id, 'balanceBatch' => $batch->id]))->assertOk();
    expect(AuditLog::query()->where('action', 'leave.balance.file.downloaded')->count())->toBe(1);
    $this->actingAs(User::factory()->create(['company_id' => $this->other->id, 'primary_role' => UserRole::HrStaff]));
    $this->get(route('leave.balance.file', ['company' => $this->company->id, 'balanceBatch' => $batch->id]))->assertNotFound();
});

test('inactive HR cannot open balance screens and platform admin can use an explicit company tenant', function (): void {
    $inactive = User::factory()->create(['company_id' => $this->company->id,
        'primary_role' => UserRole::HrStaff, 'is_active' => false]);
    actingInAdminTenant($this, $inactive, $this->company);
    Livewire::test(UploadLeaveBalance::class)->assertForbidden();

    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    actingInAdminTenant($this, $platform, $this->company);
    Livewire::test(UploadLeaveBalance::class)->assertSee('Upload leave balance');
    Livewire::test(LeaveBalanceBatches::class)->assertSee('Leave balance uploads');
});

test('employee page, dashboard employee list and statement use the same imported balance', function (): void {
    $file = balanceUiCsv();
    Livewire::test(UploadLeaveBalance::class)->set('sourceFile', $file)->call('prepare')
        ->set('balanceDate', '2026-10-20')->call('preview')->call('commit');
    $batch = BalanceBatch::query()->where('company_id', $this->company->id)->sole();
    app()->call([new ImportLeaveBalanceJob($batch->id), 'handle']);
    $balance = app(LeaveBalances::class)->current($this->employee);
    expect($balance?->remaining_days)->toBe('3.5000');
    expect(app(LeaveBalances::class)->currentFor($this->company->id, [$this->employee->id])
        ->get($this->employee->id)?->id)->toBe($balance->id);
    $this->get(route('employees.show', $this->employee))->assertOk()->assertSee('3.50')->assertSee('28.00');
    Livewire::withQueryParams(['tile' => 'active_employees'])->test(DashboardEmployees::class)
        ->assertSee('3.50')->assertSee('2026-10-20');

    $export = AttendanceExport::factory()->create(['company_id' => $this->company->id,
        'kind' => 'employee_statement_xlsx', 'requested_by' => $this->actor->id]);
    $filters = ReportFilters::fromArray($this->company, ['employee_ids' => [$this->employee->id]]);
    $header = app(ExportData::class)->header($export, $this->company, $filters);
    expect(collect($header)->contains(fn (array $line): bool => str_contains((string) $line[1], '3.50')))->toBeTrue();
    $export->kind = 'statement_pdf';
    $statement = app(ExportData::class)->statement($export, $this->company, $filters);
    expect($statement['annual_balance']?->id)->toBe($balance->id)
        ->and(view('attendance.statement', $statement)->render())->toContain('3.50');
});

test('employee page keeps the last known balance when the latest completed upload omits the employee', function (): void {
    Livewire::test(UploadLeaveBalance::class)->set('sourceFile', balanceUiCsv())->call('prepare')
        ->set('balanceDate', '2026-10-20')->call('preview')->call('commit');
    $first = BalanceBatch::query()->where('company_id', $this->company->id)->firstOrFail();
    app()->call([new ImportLeaveBalanceJob($first->id), 'handle']);

    $file = UploadedFile::fake()->createWithContent('later.csv',
        "Pers.No.,Total Remaining Annual Leave Days\n87654321,4\n");
    $reader = app(LeaveBalanceFile::class);
    $sheet = $reader->inspect($file);
    $mapping = $reader->suggest($sheet['headers'], $sheet['subheaders']);
    $import = app(LeaveBalanceImport::class);
    $preview = $import->preview($this->company, $file, $mapping, '2026-10-21', 2026);
    $second = $import->commit($this->company, $file, $this->actor, $mapping, '2026-10-21', 2026, $preview['token']);
    app()->call([new ImportLeaveBalanceJob($second->id), 'handle']);

    $this->get(route('employees.show', $this->employee))->assertOk()
        ->assertSee('Not in the latest balance report')->assertSee('3.50');
});
