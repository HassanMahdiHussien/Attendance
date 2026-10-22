<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\DayDetail;
use App\Filament\Admin\Pages\Leave\CreditLedger;
use App\Filament\Admin\Pages\Leave\LeaveBatches;
use App\Filament\Admin\Pages\Leave\LeaveRequestDetail;
use App\Filament\Admin\Pages\Leave\LeaveRequests;
use App\Filament\Admin\Pages\Leave\LeaveTypesPage;
use App\Filament\Admin\Pages\Leave\UploadLeave;
use App\Jobs\Leave\ImportLeaveJob;
use App\Models\Attendance\Day;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestDecision;
use App\Models\User;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\Reconciler;
use App\Support\Access\AdminNav;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function leaveUiCsv(): UploadedFile
{
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, LeaveFile::HEADERS);
    fputcsv($stream, ['FORM-1', '12345678', 'Fake Employee', 'Fake Approver', 'Hilla', 'Operations',
        'Approved', 'Annual Leave', '', '10/21/2026', '10/21/2026', '1', '', 'Fake Creator', '10/1/2026', 'Fake Manager', '']);
    rewind($stream);
    $contents = stream_get_contents($stream);
    fclose($stream);

    return UploadedFile::fake()->createWithContent('IRAQ All Leave REPORT-2026-10-22-10-00-00.csv', $contents);
}

function leaveUiXlsx(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'leave-ui-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(LeaveFile::HEADERS));
    $writer->addRow(Row::fromValues(['FORM-XLSX', '12345678', 'Fake Employee', 'Fake Approver', 'Hilla', 'Operations',
        'Approved', 'Annual Leave', '', '10/21/2026', '10/21/2026', '1', '', 'Fake Creator', '10/1/2026', 'Fake Manager', '']));
    $writer->close();
    $file = UploadedFile::fake()->createWithContent('IRAQ All Leave REPORT-2026-10-22-10-00-00.xlsx', file_get_contents($path));
    unlink($path);

    return $file;
}

beforeEach(function (): void {
    Queue::fake();
    Filament::setTenant(null);
    $this->company = Company::factory()->create();
    $this->other = Company::factory()->create();
    $this->foreign = app(TenantContext::class)->runForCompany($this->other, function (): array {
        $branch = Branch::factory()->create(['company_id' => $this->other->id]);
        $employee = Employee::factory()->create(['company_id' => $this->other->id, 'branch_id' => $branch->id]);
        $type = LeaveType::factory()->create(['company_id' => $this->other->id]);
        $request = LeaveRequest::factory()->create(['company_id' => $this->other->id,
            'employee_id' => $employee->id, 'owner_code' => $employee->employee_code,
            'leave_type_id' => $type->id, 'external_id' => 'FOREIGN-FORM']);
        $batch = ImportBatch::factory()->create(['company_id' => $this->other->id]);

        return compact('employee', 'type', 'request', 'batch');
    });
    app(TenantContext::class)->setCompany($this->company);
    $branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id,
        'branch_id' => $branch->id, 'employee_code' => '12345678', 'hire_date' => '2020-01-01']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('HR maps, scopes, previews and commits a leave report with reloadable progress', function (): void {
    config(['cache.default' => 'database']);
    Livewire::test(UploadLeave::class)->set('sourceFile', leaveUiCsv())
        ->call('prepare')->assertSet('step', 'mapping')
        ->call('setScopeStep')->assertSet('step', 'scope')
        ->set('scope.scope', 'full')->call('preview')->assertSet('step', 'preview')
        ->assertSee('New requests')->assertSee('Annual Leave')->assertSee('Approved')
        ->call('commit')->assertSet('step', 'progress');
    $batch = ImportBatch::query()->where('company_id', $this->company->id)->sole();
    expect($batch->scope)->toBe('full');
    Queue::assertPushed(ImportLeaveJob::class);
    app()->call([new ImportLeaveJob($batch->id), 'handle']);
    Livewire::test(UploadLeave::class)->assertSet('step', 'progress')->assertSee($batch->file_name);
    Livewire::test(LeaveBatches::class)->assertSee($batch->file_name);
});

test('saved leave mapping skips the mapping step', function (): void {
    Livewire::test(UploadLeave::class)->set('sourceFile', leaveUiCsv())->call('prepare')
        ->call('setScopeStep')->call('preview')->call('commit');
    Livewire::test(UploadLeave::class)->set('sourceFile', leaveUiCsv())->call('prepare')->assertSet('step', 'scope');
});

test('Excel leave reports reach the same mapped preview', function (): void {
    Livewire::test(UploadLeave::class)->set('sourceFile', leaveUiXlsx())
        ->call('prepare')->assertSet('step', 'mapping')
        ->call('setScopeStep')->call('preview')->assertSet('step', 'preview')
        ->assertSee('Annual Leave')->assertSee('New requests');
});

test('requests filter by status type employee date and issues', function (): void {
    $type = LeaveType::factory()->create(['is_mapped' => false]);
    LeaveRequest::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'leave_type_id' => $type->id,
        'external_id' => 'LOCAL-FORM', 'status' => 'approved', 'start_date' => '2026-10-21', 'end_date' => '2026-10-21']);
    Livewire::test(LeaveRequests::class)->assertSee('LOCAL-FORM')->assertDontSee('FOREIGN-FORM')
        ->set('status', 'pending')->assertDontSee('LOCAL-FORM')
        ->set('status', 'approved')->set('typeId', (string) $type->id)
        ->set('employeeId', (string) $this->employee->id)->set('from', '2026-10-21')->set('to', '2026-10-21')
        ->set('flag', 'unmapped_type')->assertSee('LOCAL-FORM')
        ->set('from', '2026-10-22')->assertDontSee('LOCAL-FORM');
});

test('request detail records HR actions and type page maps an unmapped type', function (): void {
    $type = LeaveType::factory()->create(['is_mapped' => false]);
    $request = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'owner_code' => $this->employee->employee_code,
        'leave_type_id' => $type->id, 'external_id' => 'LOCAL-FORM']);
    Livewire::withQueryParams(['request' => $request->id])->test(LeaveRequestDetail::class)
        ->assertSee('LOCAL-FORM')->set('reason', 'Approved correction')
        ->set('distributionMode', 'from_start')->call('confirmDistribution')->assertHasNoErrors()
        ->set('startDate', '2026-10-21')->set('endDate', '2026-10-21')
        ->set('durationDays', '0.5')->set('reason', 'Confirmed half day')->call('fixDates')->assertHasNoErrors()
        ->set('reason', 'Report corrected')->call('cancel')->assertHasNoErrors();
    expect(RequestDecision::query()->where('request_id', $request->id)->count())->toBe(3);
    Livewire::test(LeaveRequests::class)->set('status', 'cancelled')->assertSee('LOCAL-FORM');
    $decision = RequestDecision::query()->where('request_id', $request->id)->where('decision', 'cancel')->sole();
    Livewire::withQueryParams(['request' => $request->id])->test(LeaveRequestDetail::class)
        ->set('reason', 'Cancellation withdrawn')->call('withdraw', $decision->id)->assertHasNoErrors();
    expect(RequestDecision::query()->where('request_id', $request->id)->count())->toBe(4);
    Livewire::test(LeaveRequests::class)->set('status', 'cancelled')->assertDontSee('LOCAL-FORM');
    Livewire::test(LeaveTypesPage::class)->call('selectType', $type->id)
        ->set('covers', true)->set('consumes', 'none')->set('reason', 'Confirmed policy')
        ->call('save')->assertHasNoErrors();
    expect($type->fresh()->is_mapped)->toBeTrue();
});

test('credit ledger changes expiry and reverses a current deduction', function (): void {
    $type = LeaveType::factory()->create(['covers' => true, 'is_mapped' => true]);
    LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'owner_code' => $this->employee->employee_code,
        'leave_type_id' => $type->id, 'external_id' => 'CREDIT-FORM', 'status' => 'approved',
        'start_date' => '2026-10-19', 'end_date' => '2026-10-19']);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-10-19', 'status' => 'present']);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-10-20', 'status' => 'present', 'late_minutes' => 15]);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    $allocation = Allocation::query()->whereNotNull('source_credit_id')->whereNull('superseded_by_run_id')->firstOrFail();
    Livewire::test(CreditLedger::class)->set('employeeId', (string) $this->employee->id)
        ->assertSee('CREDIT-FORM')->set('reason', 'Review deduction')
        ->call('reverseDeduction', $allocation->id)->assertHasNoErrors()
        ->set('expiryDays', '30')->set('settingReason', 'Company policy')->call('saveExpiry')->assertHasNoErrors();
    expect(RequestDecision::query()->where('decision', 'reverse_deduction')->exists())->toBeTrue();
    $decision = RequestDecision::query()->where('decision', 'reverse_deduction')->sole();
    Livewire::test(CreditLedger::class)->set('employeeId', (string) $this->employee->id)
        ->set('reason', 'Correction withdrawn')->call('withdraw', $decision->id)->assertHasNoErrors();
    Livewire::test(CreditLedger::class)->assertSee(__('messages.leave_ui.leave_calendar_settings'))
        ->set('leaveCalendarRestDays', [5, 6])->set('calendarReason', 'Company calendar')
        ->call('saveLeaveCalendar')->assertHasNoErrors();
});

test('day detail renders leave reconciliation explanations in English and Arabic', function (): void {
    $type = LeaveType::factory()->create(['covers' => true, 'is_mapped' => true]);
    LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'owner_code' => $this->employee->employee_code,
        'leave_type_id' => $type->id, 'external_id' => 'ABSENCE-FORM', 'status' => 'approved',
        'start_date' => '2026-10-21', 'end_date' => '2026-10-21']);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-10-21', 'status' => 'absent']);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    Livewire::withQueryParams(['employee' => $this->employee->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)->assertSee('Leave and reconciliation')->assertSee('Absence closed by request')
        ->assertSee('from request')->assertSee('ABSENCE-FORM');
    app()->setLocale('ar');
    Livewire::withQueryParams(['employee' => $this->employee->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)->assertSee('الإجازة والتسوية')->assertSee('من الطلب')->assertSee('ABSENCE-FORM');
});

test('leave pages and actions stay within the selected tenant', function (): void {
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    expect(array_column(AdminNav::leaveItems($this->actor), 'label'))->toContain(__('messages.leave_ui.upload_title'));
    foreach ([UploadLeave::class, LeaveBatches::class, LeaveRequests::class, LeaveTypesPage::class, CreditLedger::class] as $page) {
        Livewire::test($page)->assertSee($page::getNavigationLabel());
        Filament::setTenant($this->other);
        Livewire::test($page)->assertForbidden();
        actingInAdminTenant($this, $platform, $this->other);
        Livewire::test($page)->assertSee($page::getNavigationLabel());
        actingInAdminTenant($this, $this->actor, $this->company);
    }
    Livewire::withQueryParams(['request' => $this->foreign['request']->id])
        ->test(LeaveRequestDetail::class)->assertNotFound();
    Livewire::test(LeaveRequests::class)->assertDontSee('FOREIGN-FORM');
    Livewire::test(CreditLedger::class)->set('employeeId', (string) $this->foreign['employee']->id)->assertNotFound();
    Filament::setTenant($this->other);
    Livewire::withQueryParams(['request' => $this->foreign['request']->id])
        ->test(LeaveRequestDetail::class)->assertForbidden();
    actingInAdminTenant($this, $platform, $this->other);
    Livewire::withQueryParams(['request' => $this->foreign['request']->id])
        ->test(LeaveRequestDetail::class)->assertSee('FOREIGN-FORM');
    actingInAdminTenant($this, $this->actor, $this->company);
    Livewire::test(LeaveTypesPage::class)->call('selectType', $this->foreign['type']->id)->assertNotFound();
    Livewire::test(LeaveBatches::class)->call('retry', $this->foreign['batch']->id)->assertNotFound();
});

test('leave original download is audited and unavailable to another company', function (): void {
    $otherActor = User::factory()->create(['company_id' => $this->other->id, 'primary_role' => UserRole::HrStaff]);
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $batch = ImportBatch::factory()->create(['company_id' => $this->company->id,
        'uploaded_by' => $this->actor->id, 'file_path' => 'leave-imports/original.csv']);
    Storage::disk('local')->put($batch->file_path, 'private report');
    $url = route('leave.import.file', ['company' => $this->company->id, 'leaveBatch' => $batch->id]);
    $this->get($url)->assertOk();
    expect(AuditLog::query()->where('action', 'leave.import.file.downloaded')->count())->toBe(1);
    $this->actingAs($otherActor);
    $this->get($url)->assertNotFound();
    $this->actingAs($platform);
    $this->get($url)->assertOk();
});

test('failed leave batch can be retried from the batch page', function (): void {
    $batch = ImportBatch::factory()->create(['company_id' => $this->company->id, 'uploaded_by' => $this->actor->id]);
    ImportBatchEvent::factory()->create(['company_id' => $this->company->id, 'batch_id' => $batch->id,
        'sequence' => 2, 'state' => 'failed']);
    Livewire::test(LeaveBatches::class)->call('retry', $batch->id)->assertSee($batch->file_name);
    Queue::assertPushed(ImportLeaveJob::class, fn (ImportLeaveJob $job): bool => $job->batchId === $batch->id);
});
