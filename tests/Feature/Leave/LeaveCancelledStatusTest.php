<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Leave\LeaveRequests;
use App\Jobs\Leave\ImportLeaveJob;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Day;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestDay;
use App\Models\Leave\RequestVersion;
use App\Models\User;
use App\Services\Attendance\DataIssues;
use App\Services\Attendance\FollowUp\ApproverMappings;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Leave\DayReconciliation;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\LeaveImport;
use App\Services\Leave\LeaveStatus;
use App\Services\Leave\Reconciler;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function cancelledRow(string $form, string $status, ?string $duration = '1'): array
{
    return [$form, '12345678', 'Fake Employee', 'Fake Approver', 'Hilla', 'Operations', $status,
        'Annual Leave', '', '10/19/2026', '10/19/2026', $duration, '', 'Fake Creator', '10/1/2026', 'Fake Manager', ''];
}

function cancelledSheet(array $rows, string $stamp = '2026-10-22-10-00-00'): UploadedFile
{
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, array_slice(LeaveFile::HEADERS, 2));
    foreach ($rows as $row) {
        fputcsv($stream, $row);
    }
    rewind($stream);
    $contents = stream_get_contents($stream);
    fclose($stream);

    return UploadedFile::fake()->createWithContent('IRAQ All Leave REPORT-'.$stamp.'.csv', $contents);
}

function cancelledPreview(Company $company, UploadedFile $file): array
{
    $reader = app(LeaveFile::class);
    $mapping = $reader->suggest($reader->inspect($file)['headers']);

    return [$mapping, app(LeaveImport::class)->preview($company, $file, $mapping, ['scope' => 'partial'])];
}

function cancelledImport(Company $company, User $actor, UploadedFile $file): ImportBatch
{
    [$mapping, $preview] = cancelledPreview($company, $file);
    $batch = app(LeaveImport::class)->commit($company, $file, $actor, $mapping, ['scope' => 'partial'], $preview['token']);
    app()->call([new ImportLeaveJob($batch->id), 'handle']);

    return $batch;
}

// The shape real data has from before this change: stored as pending, with the unknown-status flag and issue.
function legacyCancelled(Company $company, Employee $employee, string $form, string $state = 'completed'): LeaveRequest
{
    $batch = ImportBatch::factory()->create(['company_id' => $company->id]);
    ImportBatchEvent::create(['company_id' => $company->id, 'batch_id' => $batch->id, 'sequence' => 1, 'state' => $state]);
    $request = LeaveRequest::factory()->create([
        'company_id' => $company->id, 'employee_id' => $employee->id, 'owner_code' => $employee->employee_code,
        'external_id' => $form, 'status' => 'pending', 'raw_status' => 'Cancelled', 'flags' => ['unknown_status'],
    ]);
    $version = RequestVersion::create([
        'company_id' => $company->id, 'request_id' => $request->id, 'batch_id' => $batch->id, 'row_number' => 2,
        'snapshot_at' => now(), 'values' => ['status' => 'pending', 'raw_status' => 'Cancelled', 'flags' => ['unknown_status']],
        'raw_values' => [],
    ]);
    $request->update(['current_version_id' => $version->id]);
    app(DataIssues::class)->open($company, 'leave_unknown_status', 'leave_request', 'request:'.$request->id);

    return $request;
}

beforeEach(function (): void {
    Queue::fake();
    Filament::setTenant(null);
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $branch = Branch::factory()->create(['company_id' => $this->company->id]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $branch->id,
        'employee_code' => '12345678', 'hire_date' => '2020-01-01', 'work_pattern' => 'office']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('cancelled spellings derive to cancelled and a rejected date still wins', function (string $raw): void {
    $statuses = app(LeaveStatus::class);
    expect($statuses->derive($raw, null))->toBe(['status' => 'cancelled', 'unknown' => false])
        ->and($statuses->derive($raw, '2026-10-20')['status'])->toBe('rejected');
})->with(['Cancelled', ' cancelled ', 'Canceled']);

test('a cancelled row without duration previews clean and opens no unknown status issue', function (): void {
    $file = cancelledSheet([cancelledRow('FORM-CANCELLED', 'Cancelled', null)]);
    [, $preview] = cancelledPreview($this->company, $file);
    expect($preview['counts']['errors'])->toBe(0)
        ->and($preview['status_counts']['cancelled'])->toBe(1);

    cancelledImport($this->company, $this->actor, $file);
    $request = LeaveRequest::query()->where('external_id', 'FORM-CANCELLED')->firstOrFail();
    expect($request->status)->toBe('cancelled')
        ->and($request->flags ?? [])->not->toContain('unknown_status')
        ->and($request->flags ?? [])->not->toContain('missing_duration')
        ->and(DataIssue::query()->where('type', 'leave_unknown_status')->count())->toBe(0);
});

test('a row with another status still needs a duration', function (): void {
    [, $preview] = cancelledPreview($this->company, cancelledSheet([cancelledRow('FORM-NO-DURATION', 'Approved', null)]));
    expect($preview['counts']['errors'])->toBe(1);
});

test('an approved form cancelled in a later report reverses its allocations and keeps its history', function (): void {
    cancelledImport($this->company, $this->actor, cancelledSheet([cancelledRow('FORM-A', 'Approved')]));
    $request = LeaveRequest::query()->where('external_id', 'FORM-A')->firstOrFail();
    $request->leaveType->update(['covers' => true, 'is_mapped' => true]);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-10-19', 'status' => 'absent']);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');
    expect(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBeGreaterThan(0)
        ->and(RequestDay::query()->whereNull('superseded_by_run_id')->count())->toBeGreaterThan(0);
    $firstVersion = $request->fresh()->current_version_id;

    cancelledImport($this->company, $this->actor, cancelledSheet([cancelledRow('FORM-A', 'Cancelled')], '2026-10-23-10-00-00'));
    Queue::assertPushed(ReconcileLeaveJob::class);
    app(Reconciler::class)->run($this->company, [$this->employee->id], 'test');

    $request->refresh();
    expect($request->status)->toBe('cancelled')
        ->and($request->current_version_id)->not->toBe($firstVersion)
        ->and(RequestVersion::query()->find($firstVersion)->values['status'])->toBe('approved')
        ->and(RequestVersion::query()->where('request_id', $request->id)->count())->toBe(2)
        ->and(Allocation::query()->whereNull('superseded_by_run_id')->count())->toBe(0)
        ->and(RequestDay::query()->whereNull('superseded_by_run_id')->count())->toBe(0);
});

test('a cancelled form is not waiting for anyone', function (): void {
    LeaveRequest::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'external_id' => 'FORM-C', 'status' => 'cancelled',
        'approver_name' => 'Invented Approver', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19']);
    // A real pending form beside it proves these lists are not simply empty.
    LeaveRequest::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'external_id' => 'FORM-P', 'status' => 'pending',
        'approver_name' => 'Waiting Approver', 'start_date' => '2026-10-19', 'end_date' => '2026-10-19']);
    Day::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'work_date' => '2026-10-19', 'status' => 'absent']);

    $mappings = app(ApproverMappings::class);
    $filters = ReportFilters::fromArray($this->company, ['from' => '2026-10-01', 'to' => '2026-10-31']);
    $pending = app(DayReconciliation::class)->for($this->employee, '2026-10-19')['2026-10-19']['pending_requests'];
    $totals = app(AttendanceSummary::class)->totals($filters)['pending_leave_requests'];
    expect($mappings->unmapped($this->company))->toBe(['Waiting Approver'])
        ->and($mappings->unmappedWithCounts($this->company))->toHaveCount(1)
        ->and(collect($pending)->pluck('approver_name')->all())->toBe(['Waiting Approver'])
        ->and($totals['pending'])->toBe(1)
        ->and($totals)->not->toHaveKey('cancelled');
});

test('the cancelled filter shows imported and manual cancellations in both languages', function (string $locale): void {
    app()->setLocale($locale);
    cancelledImport($this->company, $this->actor, cancelledSheet([
        cancelledRow('FORM-IMPORTED', 'Cancelled'), cancelledRow('FORM-MANUAL', 'Approved'), cancelledRow('FORM-LIVE', 'Approved'),
    ]));
    app(LeaveActions::class)->cancel(LeaveRequest::query()->where('external_id', 'FORM-MANUAL')->firstOrFail(), 'Invented reason', $this->actor);

    Livewire::test(LeaveRequests::class)->set('status', 'cancelled')
        ->assertSee('FORM-IMPORTED')->assertSee('FORM-MANUAL')->assertDontSee('FORM-LIVE')
        ->assertSee(__('messages.leave_ui.statuses.cancelled'))->assertDontSee('leave_ui.statuses')
        ->set('status', 'approved')->assertSee('FORM-LIVE')->assertDontSee('FORM-MANUAL')->assertDontSee('FORM-IMPORTED');
})->with(['en', 'ar']);

test('the refresh command corrects old forms once and stays inside the company', function (): void {
    $request = legacyCancelled($this->company, $this->employee, 'FORM-OLD');
    $untouched = LeaveRequest::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'external_id' => 'FORM-ODD', 'status' => 'pending', 'raw_status' => 'Mystery']);
    $earlier = $request->current_version_id;

    $other = Company::factory()->create();
    $foreign = app(TenantContext::class)->runForCompany($other, function () use ($other): LeaveRequest {
        $employee = Employee::factory()->create(['company_id' => $other->id,
            'branch_id' => Branch::factory()->create(['company_id' => $other->id])->id]);

        return legacyCancelled($other, $employee, 'FORM-FOREIGN');
    });

    expect(Artisan::call('attendance:leave-status-refresh', ['--company' => $this->company->id]))->toBe(0);
    $request->refresh();
    $issue = DataIssue::query()->where('company_id', $this->company->id)->where('type', 'leave_unknown_status')->firstOrFail();
    expect($request->status)->toBe('cancelled')
        ->and($request->flags ?? [])->not->toContain('unknown_status')
        ->and($request->currentVersion->values['status'])->toBe('cancelled')
        ->and($request->currentVersion->values['flags'])->toBe([])
        ->and(RequestVersion::query()->find($earlier)->values['status'])->toBe('pending')
        ->and(RequestVersion::query()->where('request_id', $request->id)->count())->toBe(2)
        ->and($issue->status)->toBe('resolved')
        ->and($issue->open_key)->toBeNull()
        ->and($untouched->fresh()->status)->toBe('pending')
        ->and(AuditLog::query()->where('action', 'leave.status.refreshed')->where('entity_id', $request->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'attendance.issue.resolved')->where('entity_id', $issue->id)->count())->toBe(1);
    Queue::assertPushed(ReconcileLeaveJob::class);

    Artisan::call('attendance:leave-status-refresh', ['--company' => $this->company->id]);
    expect(RequestVersion::query()->where('request_id', $request->id)->count())->toBe(2);

    app(TenantContext::class)->runForCompany($other, function () use ($foreign, $other): void {
        expect($foreign->fresh()->status)->toBe('pending')
            ->and(DataIssue::query()->where('company_id', $other->id)->where('status', 'open')->count())->toBe(1);
    });
});

test('forms in one batch take separate version rows', function (): void {
    $first = legacyCancelled($this->company, $this->employee, 'FORM-ONE');
    $second = LeaveRequest::factory()->create(['company_id' => $this->company->id, 'employee_id' => $this->employee->id,
        'owner_code' => $this->employee->employee_code, 'external_id' => 'FORM-TWO', 'status' => 'pending', 'raw_status' => 'Cancelled']);
    $batchId = $first->currentVersion->batch_id;
    $version = RequestVersion::create(['company_id' => $this->company->id, 'request_id' => $second->id, 'batch_id' => $batchId,
        'row_number' => 3, 'snapshot_at' => now(), 'values' => ['status' => 'pending', 'flags' => ['unknown_status']], 'raw_values' => []]);
    $second->update(['current_version_id' => $version->id]);

    Artisan::call('attendance:leave-status-refresh', ['--company' => $this->company->id]);
    expect(RequestVersion::query()->where('batch_id', $batchId)->orderBy('row_number')->pluck('row_number')->all())->toBe([2, 3, 4, 5]);
});

test('the refresh command leaves a form whose import can still resume', function (): void {
    $request = legacyCancelled($this->company, $this->employee, 'FORM-UNFINISHED', 'processing');
    Artisan::call('attendance:leave-status-refresh', ['--company' => $this->company->id]);
    expect($request->fresh()->status)->toBe('pending')
        ->and(RequestVersion::query()->where('request_id', $request->id)->count())->toBe(1)
        ->and(Artisan::output())->toContain(__('messages.leave_import.status_refresh_counts', ['changed' => 0, 'resolved' => 0, 'skipped' => 1]));
});

test('a corrected form with no duration loses its false duration issue and a later identical report changes nothing', function (): void {
    $sheet = fn (string $stamp, array $more = []): UploadedFile => cancelledSheet([cancelledRow('FORM-OLD', 'Cancelled', null), ...$more], $stamp);
    // A byte-identical file is refused as already imported, so the later report carries one more form.
    $later = fn (): UploadedFile => $sheet('2026-10-23-10-00-00', [cancelledRow('FORM-NEW', 'Approved')]);
    cancelledImport($this->company, $this->actor, $sheet('2026-10-22-10-00-00'));
    $request = LeaveRequest::query()->where('external_id', 'FORM-OLD')->firstOrFail();
    // Put it back in the shape the old parser stored, with the file's real raw values.
    $values = ['status' => 'pending', 'flags' => ['unknown_status', 'missing_duration']] + $request->currentVersion->values;
    $old = RequestVersion::create(['company_id' => $this->company->id, 'request_id' => $request->id, 'batch_id' => $request->currentVersion->batch_id,
        'row_number' => 99, 'snapshot_at' => $request->currentVersion->snapshot_at, 'values' => $values, 'raw_values' => $request->currentVersion->raw_values]);
    $request->update(['status' => 'pending', 'flags' => $values['flags'], 'current_version_id' => $old->id]);
    app(DataIssues::class)->open($this->company, 'leave_unknown_status', 'leave_request', 'request:'.$request->id);
    app(DataIssues::class)->open($this->company, 'leave_invalid_duration', 'leave_request', 'request:'.$request->id);

    Artisan::call('attendance:leave-status-refresh', ['--company' => $this->company->id]);
    $request->refresh();
    $versions = RequestVersion::query()->where('request_id', $request->id)->count();
    expect($request->status)->toBe('cancelled')->and($request->flags)->toBe([])
        ->and(DataIssue::query()->where('status', 'open')->count())->toBe(0);

    [, $preview] = cancelledPreview($this->company, $later());
    expect($preview['counts']['unchanged'])->toBe(1)->and($preview['counts']['changed'])->toBe(0);
    cancelledImport($this->company, $this->actor, $later());
    expect(RequestVersion::query()->where('request_id', $request->id)->count())->toBe($versions)
        ->and(DataIssue::query()->where('status', 'open')->count())->toBe(0);
});

test('a bad duration on a cancelled form stays open for HR', function (): void {
    $request = legacyCancelled($this->company, $this->employee, 'FORM-BAD');
    $version = $request->currentVersion;
    $values = ['flags' => ['unknown_status', 'duration_mismatch']] + $version->values;
    $next = RequestVersion::create(['company_id' => $this->company->id, 'request_id' => $request->id, 'batch_id' => $version->batch_id,
        'row_number' => 3, 'snapshot_at' => $version->snapshot_at, 'values' => $values, 'raw_values' => []]);
    $request->update(['flags' => $values['flags'], 'current_version_id' => $next->id]);
    app(DataIssues::class)->open($this->company, 'leave_invalid_duration', 'leave_request', 'request:'.$request->id);

    Artisan::call('attendance:leave-status-refresh', ['--company' => $this->company->id]);
    expect($request->fresh()->flags)->toBe(['duration_mismatch'])
        ->and(DataIssue::query()->where('status', 'open')->pluck('type')->all())->toBe(['leave_invalid_duration']);
});

test('a later report that recognises the status clears the old issue, and the command clears one left behind', function (): void {
    cancelledImport($this->company, $this->actor, cancelledSheet([cancelledRow('FORM-OLD', 'Mystery')]));
    $request = LeaveRequest::query()->where('external_id', 'FORM-OLD')->firstOrFail();
    expect(DataIssue::query()->where('type', 'leave_unknown_status')->where('status', 'open')->count())->toBe(1);

    cancelledImport($this->company, $this->actor, cancelledSheet([cancelledRow('FORM-OLD', 'Cancelled')], '2026-10-23-10-00-00'));
    expect($request->fresh()->status)->toBe('cancelled')
        ->and(DataIssue::query()->where('type', 'leave_unknown_status')->where('status', 'open')->count())->toBe(0);

    app(DataIssues::class)->open($this->company, 'leave_unknown_status', 'leave_request', 'request:'.$request->id);
    $versions = RequestVersion::query()->where('request_id', $request->id)->count();
    Artisan::call('attendance:leave-status-refresh', ['--company' => $this->company->id]);
    expect(DataIssue::query()->where('type', 'leave_unknown_status')->where('status', 'open')->count())->toBe(0)
        ->and(RequestVersion::query()->where('request_id', $request->id)->count())->toBe($versions);
});

test('an older report cannot clear the issue of a newer unknown status', function (): void {
    cancelledImport($this->company, $this->actor, cancelledSheet([cancelledRow('FORM-OLD', 'Mystery')], '2026-10-23-10-00-00'));
    cancelledImport($this->company, $this->actor, cancelledSheet([cancelledRow('FORM-OLD', 'Approved')], '2026-10-22-10-00-00'));
    expect(DataIssue::query()->where('type', 'leave_unknown_status')->where('status', 'open')->count())->toBe(1);
});

test('the refresh command refuses a missing company', function (): void {
    expect(Artisan::call('attendance:leave-status-refresh'))->toBe(1);
});
