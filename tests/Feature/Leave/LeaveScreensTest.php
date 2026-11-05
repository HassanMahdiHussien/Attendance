<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\DayDetail;
use App\Filament\Admin\Pages\Leave\CreditLedger;
use App\Filament\Admin\Pages\Leave\LeaveBatches;
use App\Filament\Admin\Pages\Leave\LeaveDescriptions;
use App\Filament\Admin\Pages\Leave\LeaveRequestDetail;
use App\Filament\Admin\Pages\Leave\LeaveRequests;
use App\Filament\Admin\Pages\Leave\LeaveTypesPage;
use App\Filament\Admin\Pages\Leave\UploadLeave;
use App\Jobs\Leave\ImportLeaveJob;
use App\Jobs\Leave\ReadLeaveDescriptionsJob;
use App\Models\Attendance\Day;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\DescriptionReading;
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
    fputcsv($stream, array_slice(LeaveFile::HEADERS, 2));
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
    $writer->addRow(Row::fromValues(array_slice(LeaveFile::HEADERS, 2)));
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
        ->call('confirmImport')->assertSet('step', 'progress');
    $batch = ImportBatch::query()->where('company_id', $this->company->id)->sole();
    expect($batch->scope)->toBe('full');
    Queue::assertPushed(ImportLeaveJob::class);
    app()->call([new ImportLeaveJob($batch->id), 'handle']);
    Livewire::test(UploadLeave::class)->assertSet('step', 'progress')->assertSee($batch->file_name);
    Livewire::test(LeaveBatches::class)->assertSee($batch->file_name);
});

test('saved leave mapping skips the mapping step', function (): void {
    Livewire::test(UploadLeave::class)->set('sourceFile', leaveUiCsv())->call('prepare')
        ->call('setScopeStep')->call('preview')->call('confirmImport');
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

test('leave request and credit employee pickers search without loading every employee', function (): void {
    Livewire::test(LeaveRequests::class)->set('employeeSearch', $this->employee->employee_code)
        ->assertSee($this->employee->employee_code)->assertDontSee($this->foreign['employee']->employee_code)
        ->call('chooseEmployee', $this->employee->id)->assertSet('employeeId', (string) $this->employee->id)
        ->call('clearEmployee')->assertSet('employeeId', '');
    Livewire::test(CreditLedger::class)->set('employeeSearch', $this->employee->employee_code)
        ->assertSee($this->employee->employee_code)
        ->call('chooseEmployee', $this->employee->id)->assertSet('employeeId', (string) $this->employee->id);
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

test('leave types show labelled columns and an empty state', function (): void {
    Livewire::test(LeaveTypesPage::class)->assertSee('No records match this view.')
        ->assertSee('<th>Type</th>', escape: false);

    LeaveType::factory()->create(['company_id' => $this->company->id, 'name' => 'Sample leave', 'is_mapped' => false]);

    Livewire::test(LeaveTypesPage::class)->assertSee('Sample leave')
        ->assertSee('data-label="Mapped"', escape: false)
        ->assertSee('data-label="Actions"', escape: false);
});

test('a compensation request shows its exceeded entitlement flag', function (): void {
    $type = LeaveType::factory()->create(['covers' => true, 'is_mapped' => true,
        'consumes' => 'rest_day_entitlement']);
    $request = LeaveRequest::factory()->create(['employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'leave_type_id' => $type->id,
        'external_id' => 'COMP-FORM', 'flags' => ['entitlement_exceeded']]);

    Livewire::withQueryParams(['request' => $request->id])->test(LeaveRequestDetail::class)
        ->assertSee('COMP-FORM')->assertSee('Rest-day entitlement exceeded');
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
    expect(array_column(AdminNav::leaveItems($this->actor), 'label'))->not->toContain(__('messages.leave_ui.upload_title'));
    foreach ([UploadLeave::class, LeaveBatches::class, LeaveRequests::class, LeaveTypesPage::class,
        CreditLedger::class, LeaveDescriptions::class] as $page) {
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

test('leave notes list only approved forms and category filters stay scoped', function (): void {
    $request = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'external_id' => 'FORM-READ', 'status' => 'approved']);
    $reading = DescriptionReading::create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'text_hash' => hash('sha256', 'invented note'),
        'category' => 'reason_only', 'worked_dates' => [], 'confidence' => 'high', 'status' => 'not_needed']);
    foreach (['draft', 'pending', 'rejected'] as $status) {
        $hidden = LeaveRequest::factory()->create(['company_id' => $this->company->id,
            'employee_id' => $this->employee->id, 'external_id' => 'FORM-'.strtoupper($status), 'status' => $status]);
        DescriptionReading::create(['company_id' => $this->company->id,
            'request_id' => $hidden->id, 'text_hash' => hash('sha256', $status),
            'category' => 'reason_only', 'worked_dates' => [], 'confidence' => 'high', 'status' => 'not_needed']);
    }
    $page = Livewire::test(LeaveDescriptions::class)->assertSee('FORM-READ')
        ->assertDontSee('FORM-DRAFT')->assertDontSee('FORM-PENDING')->assertDontSee('FORM-REJECTED')
        ->assertSee(__('messages.leave_descriptions.note_count', ['count' => 1]))
        ->set('category', 'medical')->assertDontSee('FORM-READ')
        ->set('category', 'reason_only')->assertSee('FORM-READ');
    expect($page->instance()->counts()['total'])->toBe(1)
        ->and((int) $page->instance()->counts()['categories']['reason_only'])->toBe(1)
        ->and($reading->fresh()->status)->toBe('not_needed');
});

test('description review filters failures and retries all of them in one action', function (): void {
    $request = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'external_id' => 'FORM-FAILED', 'status' => 'approved']);
    $reading = DescriptionReading::create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'text_hash' => hash('sha256', 'invented reason'),
        'status' => 'proposed', 'error_code' => 'daily_cap']);
    $draft = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'external_id' => 'FORM-DRAFT-FAILED', 'status' => 'draft']);
    $hiddenFailure = DescriptionReading::create(['company_id' => $this->company->id,
        'request_id' => $draft->id, 'text_hash' => hash('sha256', 'another invented reason'),
        'status' => 'proposed', 'error_code' => 'daily_cap']);
    Livewire::test(LeaveDescriptions::class)->assertSee('FORM-FAILED')->assertDontSee('FORM-DRAFT-FAILED')
        ->assertSee(__('messages.leave_descriptions.failures', ['count' => 2]))
        ->call('retryAll')->assertHasNoErrors();
    expect($reading->fresh()->error_code)->toBeNull()
        ->and($hiddenFailure->fresh()->error_code)->toBeNull();
    Queue::assertPushed(ReadLeaveDescriptionsJob::class);
});

test('description review says plainly when the AI service refused the request', function (): void {
    $request = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'external_id' => 'FORM-REFUSED', 'status' => 'approved']);
    DescriptionReading::create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'text_hash' => hash('sha256', 'invented reason'),
        'status' => 'proposed', 'error_code' => 'ai_unavailable']);
    Livewire::test(LeaveDescriptions::class)->assertSee(__('messages.leave_descriptions.ai_unavailable'))
        ->assertDontSee('ai_unavailable');
});

test('leave notes are read only and viewing them writes no audit or reading', function (): void {
    $type = LeaveType::factory()->create(['company_id' => $this->company->id, 'name' => 'Compensation Leave']);
    $request = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'leave_type_id' => $type->id,
        'external_id' => 'FORM-ONLY-VIEW', 'status' => 'approved']);
    $reading = DescriptionReading::create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'text_hash' => hash('sha256', 'invented note'),
        'category' => 'compensation_for_day', 'worked_dates' => ['2026-10-09'],
        'confidence' => 'high', 'status' => 'proposed']);
    $beforeAudit = AuditLog::query()->count();
    $beforeReading = $reading->fresh()->toArray();
    Livewire::test(LeaveDescriptions::class)->assertSee('FORM-ONLY-VIEW')
        ->assertSee(__('messages.leave_descriptions.employee_wrote'))
        ->assertDontSee('Confirm')->assertDontSee('Reject')->assertDontSee('Confirm all')
        ->assertDontSee(__('messages.leave_descriptions.undo'));
    expect(method_exists(LeaveDescriptions::class, 'decide'))->toBeFalse()
        ->and(method_exists(LeaveDescriptions::class, 'confirmAll'))->toBeFalse()
        ->and(AuditLog::query()->count())->toBe($beforeAudit)
        ->and($reading->fresh()->toArray())->toBe($beforeReading);
});

test('leave notes explain compensation and hourly readings with and without dates', function (): void {
    foreach ([
        ['FORM-COMP-DATE', 'compensation_for_day', ['2026-10-09'], null, null, null, 'high'],
        ['FORM-COMP-EMPTY', 'compensation_for_day', [], null, null, null, 'medium'],
        ['FORM-HOURLY-PERIOD', 'hourly_settlement', [], '2026-07-01', '2026-08-31', 2, 'high'],
        ['FORM-HOURLY-EMPTY', 'hourly_settlement', [], null, null, null, 'low'],
    ] as [$form, $category, $dates, $from, $to, $hours, $confidence]) {
        $request = LeaveRequest::factory()->create(['company_id' => $this->company->id,
            'employee_id' => $this->employee->id, 'external_id' => $form, 'status' => 'approved']);
        DescriptionReading::create(['company_id' => $this->company->id,
            'request_id' => $request->id, 'text_hash' => hash('sha256', $form),
            'category' => $category, 'worked_dates' => $dates,
            'settled_from' => $from, 'settled_to' => $to, 'hours' => $hours,
            'confidence' => $confidence, 'status' => 'proposed']);
    }
    Livewire::test(LeaveDescriptions::class)
        ->assertSee('This leave is for working on')->assertSee('2026-10-09')
        ->assertSee(__('messages.leave_descriptions.compensation_no_dates'))
        ->assertSee('Settles late or early hours for')->assertSee('2026-07-01')
        ->assertSee('2026-08-31')->assertSee('(2 hours)')
        ->assertSee(__('messages.leave_descriptions.hourly_no_period'))
        ->assertSee(__('messages.leave_descriptions.low_confidence'))
        ->set('category', 'compensation_for_day')
        ->assertDontSee(__('messages.leave_descriptions.low_confidence'));
    app()->setLocale('ar');
    Livewire::test(LeaveDescriptions::class)
        ->assertSee('هذه الإجازة مقابل العمل يوم')
        ->assertSee(__('messages.leave_descriptions.compensation_no_dates'))
        ->assertSee('تسوية ساعات تأخير أو خروج مبكر عن الفترة من')
        ->assertSee(__('messages.leave_descriptions.hourly_no_period'));
});

test('undo is available only for earlier decisions and cannot reach another company', function (): void {
    $request = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'external_id' => 'FORM-DECIDED', 'status' => 'approved']);
    $reading = DescriptionReading::create(['company_id' => $this->company->id,
        'request_id' => $request->id, 'text_hash' => hash('sha256', 'invented decision'),
        'category' => 'reason_only', 'worked_dates' => [], 'confidence' => 'high',
        'status' => 'confirmed']);
    $rejectedRequest = LeaveRequest::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'external_id' => 'FORM-READING-REJECTED', 'status' => 'approved']);
    $rejectedReading = DescriptionReading::create(['company_id' => $this->company->id,
        'request_id' => $rejectedRequest->id, 'text_hash' => hash('sha256', 'another invented decision'),
        'category' => 'reason_only', 'worked_dates' => [], 'confidence' => 'high',
        'status' => 'rejected']);
    $otherReading = app(TenantContext::class)->runForCompany($this->other, fn () => DescriptionReading::create([
        'company_id' => $this->other->id, 'request_id' => $this->foreign['request']->id,
        'text_hash' => hash('sha256', 'foreign invented note'), 'category' => 'reason_only',
        'worked_dates' => [], 'confidence' => 'high', 'status' => 'confirmed',
    ]));
    Livewire::test(LeaveDescriptions::class)->call('undo', $otherReading->id)->assertNotFound();
    Livewire::test(LeaveDescriptions::class)->assertSee(__('messages.leave_descriptions.undo'))
        ->call('undo', $reading->id)->assertHasNoErrors();
    Livewire::test(LeaveDescriptions::class)->call('undo', $rejectedReading->id)->assertHasNoErrors();
    expect($reading->fresh()->status)->toBe('proposed')
        ->and($rejectedReading->fresh()->status)->toBe('proposed')
        ->and(app(TenantContext::class)->runForCompany($this->other, fn () => $otherReading->fresh()->status))->toBe('confirmed');
});
