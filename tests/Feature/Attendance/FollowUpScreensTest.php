<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\ApproverMappingsPage;
use App\Filament\Admin\Pages\Attendance\DayDetail;
use App\Filament\Admin\Pages\Attendance\FollowUpCasesPage;
use App\Filament\Admin\Pages\Attendance\NotificationsPage;
use App\Jobs\Attendance\GenerateAttendanceExportJob;
use App\Jobs\Attendance\SendNotificationMessageJob;
use App\Livewire\Attendance\EmployeeFollowUp;
use App\Livewire\Dashboard\HrDashboard;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\Day;
use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\NotificationAttempt;
use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Models\Attendance\NotificationMessageCase;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\User;
use App\Services\Attendance\FollowUp\ApproverMappings;
use App\Services\Attendance\FollowUp\FollowUpCases;
use App\Services\Attendance\Reporting\ExportData;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(now('Asia/Baghdad')->setDate(2026, 10, 25));
    Filament::setTenant(null);
    $this->other = Company::factory()->create();
    $this->foreign = app(TenantContext::class)->runForCompany($this->other, function (): FollowUpCase {
        $employee = Employee::factory()->create(['company_id' => $this->other->id]);

        $batch = NotificationBatch::factory()->create(['company_id' => $this->other->id]);
        $this->foreignMessage = NotificationMessage::factory()->create([
            'company_id' => $this->other->id, 'batch_id' => $batch->id,
        ]);

        return FollowUpCase::factory()->create(['company_id' => $this->other->id, 'employee_id' => $employee->id]);
    });
    $this->company = Company::factory()->create();
    completeCompanySetup($this->company);
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id]);
    $this->day = Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'branch_id' => $this->branch->id, 'work_date' => '2026-10-21', 'status' => 'absent']);
    $this->case = FollowUpCase::factory()->create(['company_id' => $this->company->id,
        'employee_id' => $this->employee->id, 'day_id' => $this->day->id, 'work_date' => '2026-10-21']);
    $this->hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->hr, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('case filters, dashboard counts and export rows share the same scoped cases', function (): void {
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
    $reader = app(FollowUpCases::class);
    expect($reader->tileTotals($filters)['open_cases'])->toBe(1)
        ->and($reader->forTile($filters, 'open_cases')->pluck('id')->all())->toBe([$this->case->id]);
    $export = AttendanceExport::factory()->create(['company_id' => $this->company->id, 'kind' => 'follow_up_cases']);
    $rows = iterator_to_array(app(ExportData::class)->rows($export, $filters), false);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['employee_code'])->toBe($this->employee->employee_code);

    $dashboard = Livewire::test(HrDashboard::class)->assertSee('Open cases');
    expect($dashboard->instance()->tileUrl('open_cases'))->toContain('state=open_cases');
    $statement = AttendanceExport::factory()->create(['company_id' => $this->company->id,
        'kind' => 'statement_pdf']);
    $employeeFilters = ReportFilters::fromArray($this->company, ['from' => '2026-10-01',
        'to' => '2026-10-31', 'employee_ids' => [$this->employee->id]]);
    expect(app(ExportData::class)->statement($statement, $this->company, $employeeFilters)['follow_up_cases']
        ->pluck('id')->all())->toBe([$this->case->id]);

    Livewire::test(FollowUpCasesPage::class)->set('from', '2026-10-01')->set('to', '2026-10-31')
        ->assertSee($this->employee->name)->assertDontSee(Employee::withoutGlobalScopes()->findOrFail($this->foreign->employee_id)->name)
        ->set('issueType', 'late')->assertDontSee($this->employee->name)
        ->set('issueType', 'absence')->assertSee($this->employee->name)
        ->set('state', 'closed')->assertDontSee($this->employee->name)
        ->set('state', 'open_cases')->assertSee($this->employee->name)
        ->call('export')->assertHasNoErrors();
    $requested = AttendanceExport::query()->where('company_id', $this->company->id)
        ->where('kind', 'follow_up_cases')->latest('id')->firstOrFail();
    expect($requested->filters['case_state'])->toBe('open_cases')
        ->and($requested->filters['case_issue_type'])->toBe('absence');
    Storage::fake('local');
    app()->call([new GenerateAttendanceExportJob($requested->id), 'handle']);
    $reader = new Reader;
    $reader->open(Storage::disk('local')->path($requested->fresh()->file_path));
    $xlsxRows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $xlsxRows[] = $row->toArray();
        }
        break;
    }
    $reader->close();
    $total = collect($xlsxRows)->first(fn (array $row): bool => ($row[0] ?? null) === 'Total');
    expect($requested->fresh()->row_count)->toBe(1)
        ->and(collect($xlsxRows)->pluck(0))->toContain($this->employee->employee_code)
        ->and((int) $total[5])->toBe(480);
});

test('case appears on the day and employee pages and HR can close it with a reason', function (): void {
    Livewire::withQueryParams(['employee' => $this->employee->id, 'date' => '2026-10-21'])
        ->test(DayDetail::class)->assertSee('Follow-up cases');
    Livewire::test(EmployeeFollowUp::class, ['employeeId' => $this->employee->id])
        ->assertSee('Follow-up cases')->assertSee('Absence')
        ->set('email', 'person@example.test')->set('fallbackEmail', 'manager@example.test')
        ->set('reason', 'Confirmed contact details')->call('save')->assertHasNoErrors();
    expect($this->employee->fresh()->email)->toBe('person@example.test');

    Livewire::test(FollowUpCasesPage::class)->set('closeReason', 'Manager confirmed resolution')
        ->call('closeCase', $this->case->id)->assertHasNoErrors();
    expect($this->case->fresh()->state)->toBe('closed');
});

test('notification settings and batches are tenant scoped', function (): void {
    Livewire::test(NotificationsPage::class)->assertSee('Notification settings')
        ->set('mode', 'auto')->set('reason', 'Approved automatic delivery')
        ->call('saveSettings')->assertHasNoErrors()
        ->call('build')->assertHasNoErrors();
    expect(NotificationBatch::query()->where('company_id', $this->company->id)->count())->toBe(1);
    Livewire::test(ApproverMappingsPage::class)->assertSee('Approver mappings');
});

test('notification draft preview, exclusion and send use the delivery service', function (): void {
    $batch = NotificationBatch::factory()->create(['company_id' => $this->company->id]);
    $message = NotificationMessage::factory()->create(['company_id' => $this->company->id, 'batch_id' => $batch->id]);
    NotificationMessageCase::factory()->create(['company_id' => $this->company->id,
        'message_id' => $message->id, 'case_id' => $this->case->id,
        'snapshot' => ['employee_name' => $this->employee->name,
            'employee_code' => $this->employee->employee_code, 'work_date' => '2026-10-21',
            'items' => [['type' => 'absence', 'minutes' => 480]], 'route' => ['kind' => 'manager']]]);
    Livewire::test(NotificationsPage::class)->assertSee($message->address)
        ->call('preview', $message->id)->assertSee('Attendance follow-up')
        ->set('messageReasons.'.$message->id, 'Wrong recipient')->call('exclude', $message->id)
        ->assertHasNoErrors();
    expect($message->fresh()->status)->toBe('excluded');
    $second = NotificationMessage::factory()->create(['company_id' => $this->company->id,
        'batch_id' => $batch->id]);
    Livewire::test(NotificationsPage::class)->call('send', $batch->id)->assertHasNoErrors();
    expect($second->fresh()->status)->toBe('queued');
    Queue::assertPushed(SendNotificationMessageJob::class);
});

test('notification page retries failed delivery and requires a reason to cancel', function (): void {
    $batch = NotificationBatch::factory()->create(['company_id' => $this->company->id]);
    $message = NotificationMessage::factory()->create(['company_id' => $this->company->id,
        'batch_id' => $batch->id, 'status' => 'failed', 'last_error' => 'mail_delivery_failed']);
    Livewire::test(NotificationsPage::class)->assertSee('Mail delivery failed')->assertSee('Retry')
        ->call('retry', $message->id)->assertHasNoErrors()
        ->call('cancel', $batch->id)->assertHasErrors(['reason' => 'required'])
        ->set('reason', 'The recipient list was replaced')
        ->call('cancel', $batch->id)->assertHasNoErrors();
    expect($message->fresh()->status)->toBe('excluded')
        ->and($batch->fresh()->status)->toBe('cancelled');
});

test('notification page exposes the uncertain delivery decision', function (): void {
    $batch = NotificationBatch::factory()->create(['company_id' => $this->company->id,
        'status' => 'sending']);
    $message = NotificationMessage::factory()->create(['company_id' => $this->company->id,
        'batch_id' => $batch->id, 'status' => 'uncertain', 'attempts' => 1]);
    NotificationAttempt::factory()->create(['company_id' => $this->company->id,
        'message_id' => $message->id, 'attempt_number' => 1, 'result' => 'started']);
    Livewire::test(NotificationsPage::class)->assertSee('Delivery uncertain')
        ->call('resolve', $message->id, 'resend')->assertHasNoErrors();
    expect($message->fresh()->status)->toBe('queued');
});

test('an unmapped approver can be confirmed to a company employee', function (): void {
    LeaveRequest::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'approver_name' => 'Approver Name', 'status' => 'pending']);
    LeaveRequest::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'approver_name' => '  approver   name  ', 'status' => 'pending']);
    $unmapped = app(ApproverMappings::class)->unmappedWithCounts($this->company);
    expect($unmapped)->toHaveCount(1)->and($unmapped[0]['count'])->toBe(2);
    Livewire::test(ApproverMappingsPage::class)->assertSee('Approver Name')
        ->set('employeeIds.'.sha1('approver name'), $this->employee->id)
        ->call('confirm', 'Approver Name')->assertHasNoErrors();
});

test('employee case panel refreshes a missing-address route and displays the reason', function (): void {
    $panel = Livewire::test(EmployeeFollowUp::class, ['employeeId' => $this->employee->id]);
    $this->case->update(['routes' => [[
        'key' => 'manager', 'kind' => 'manager', 'recipient_employee_id' => null,
        'recipient_available' => false, 'external_id' => null,
    ]]]);
    $panel->call('$refresh')->assertSee('Manager and fallback email missing');
});

test('notification mutations require an active user with the send gate', function (): void {
    $inactive = User::factory()->create(['company_id' => $this->company->id,
        'primary_role' => UserRole::HrStaff, 'is_active' => false]);
    actingInAdminTenant($this, $inactive, $this->company);
    Livewire::test(NotificationsPage::class)->assertForbidden();
    Livewire::test(ApproverMappingsPage::class)->assertForbidden();
    Livewire::test(FollowUpCasesPage::class)->assertForbidden();
    expect(NotificationBatch::query()->where('company_id', $this->company->id)->count())->toBe(0);
});

test('another company case cannot be closed from the current panel', function (): void {
    Livewire::test(FollowUpCasesPage::class)->set('closeReason', 'No access')
        ->call('closeCase', $this->foreign->id)->assertNotFound();
    Livewire::test(NotificationsPage::class)->call('preview', $this->foreignMessage->id)->assertNotFound();
    Livewire::test(ApproverMappingsPage::class)
        ->set('employeeIds.'.sha1('foreign approver'), $this->foreign->employee_id)
        ->call('confirm', 'Foreign approver')->assertNotFound();
    Livewire::test(EmployeeFollowUp::class, ['employeeId' => $this->foreign->employee_id])->assertForbidden();
});

test('platform admin can use the company pages through an explicit tenant', function (): void {
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    actingInAdminTenant($this, $platform, $this->company);
    Livewire::test(FollowUpCasesPage::class)->assertSee('Follow-up cases');
    Livewire::test(NotificationsPage::class)->assertSee('Notification settings')
        ->set('reason', 'Approved for this company')->call('saveSettings')->assertHasNoErrors();
    Livewire::test(ApproverMappingsPage::class)->assertSee('Approver mappings');
});

test('company HR cannot open another tenant panel pages', function (): void {
    Filament::setTenant($this->other);
    foreach ([FollowUpCasesPage::class, NotificationsPage::class, ApproverMappingsPage::class] as $page) {
        Livewire::test($page)->assertForbidden();
    }
});
