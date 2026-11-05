<?php

use App\Enums\UserRole;
use App\Jobs\Leave\ImportLeaveJob;
use App\Models\Attendance\DataIssue;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use App\Models\Leave\ImportMapping;
use App\Models\Leave\ImportRow;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\LeaveType;
use App\Models\Leave\RequestVersion;
use App\Models\User;
use App\Services\Employees\EmployeeHistory;
use App\Services\Leave\LeaveFile;
use App\Services\Leave\LeaveImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpKernel\Exception\HttpException;

function leaveSheet(array $rows, string $name = 'IRAQ All Leave REPORT-2026-10-22-10-00-00.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'leave-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(array_slice(LeaveFile::HEADERS, 2)));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function leaveRow(string $form, string $status = 'Approved', string $type = 'Annual Leave', string $start = '10/19/2026', string $end = '10/19/2026', ?string $duration = '1'): array
{
    return [$form, '12345678', 'Fake Employee', 'Fake Approver', 'Hilla', 'Operations', $status,
        $type, '', $start, $end, $duration, '', 'Fake Creator', '10/1/2026', 'Fake Manager', ''];
}

function importLeaveSheet(UploadedFile $file, array $scope = ['scope' => 'partial']): ImportBatch
{
    $company = Company::findOrFail(app(TenantContext::class)->companyId());
    $actor = auth()->user();
    $reader = app(LeaveFile::class);
    $mapping = $reader->suggest($reader->inspect($file)['headers']);
    $import = app(LeaveImport::class);
    $preview = $import->preview($company, $file, $mapping, $scope);
    $batch = $import->commit($company, $file, $actor, $mapping, $scope, $preview['token']);
    app()->call([new ImportLeaveJob($batch->id), 'handle']);

    return $batch;
}

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $branch = Branch::factory()->create();
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->employee = Employee::factory()->create(['branch_id' => $branch->id, 'employee_code' => '12345678']);
    $this->actingAs($this->actor);
});

test('real report shape streams dates, statuses, types and bounded issues', function (): void {
    $rejected = leaveRow('FORM-REJECT', 'Manager Approval');
    $rejected[16] = '10/20/2026';
    $draft = leaveRow('FORM-DRAFT', 'Leave Entry', duration: null);
    $other = leaveRow('FORM-OTHER', type: 'Other');
    $other[8] = 'Unpaid Leave';
    $file = leaveSheet([
        leaveRow('FORM-APPROVED'), $rejected, $draft, leaveRow('FORM-UNKNOWN', type: '0200'),
        $other, leaveRow('FORM-OLD', start: '1/1/1970', end: '1/1/1970'),
        leaveRow('FORM-HALF', duration: '0.5'),
        leaveRow('FORM-MULTI', start: '10/19/2026', end: '10/21/2026', duration: '2.5'),
    ]);
    $reader = app(LeaveFile::class);
    $sheet = $reader->inspect($file);
    expect($sheet['headers'])->toBe(array_slice(LeaveFile::HEADERS, 2))
        ->and($sheet['snapshot_at'])->toBe('2026-10-22 10:00:00');
    $mapping = $reader->suggest($sheet['headers']);
    $preview = app(LeaveImport::class)->preview($this->company, $file, $mapping, ['scope' => 'full']);
    expect($preview['counts']['rows'])->toBe(8)
        ->and($preview['suggested_scope']['period_from'])->toBe('2026-10-19')
        ->and(count($preview['samples']['errors']))->toBeLessThanOrEqual(50)
        ->and(LeaveRequest::count())->toBe(0);
    $batch = importLeaveSheet($file, ['scope' => 'full']);
    expect(ImportBatchEvent::query()->where('batch_id', $batch->id)->orderByDesc('sequence')->value('state'))->toBe('completed')
        ->and(ImportRow::query()->where('batch_id', $batch->id)->count())->toBe(8)
        ->and(LeaveRequest::query()->where('external_id', 'FORM-REJECT')->value('status'))->toBe('rejected')
        ->and(LeaveRequest::query()->where('external_id', 'FORM-DRAFT')->value('status'))->toBe('draft')
        ->and(LeaveType::query()->where('normalized_key', 'other|unpaid leave')->firstOrFail()->covers)->toBeTrue()
        ->and(LeaveType::query()->where('normalized_key', '0200|')->firstOrFail()->is_mapped)->toBeFalse()
        ->and(DataIssue::query()->where('type', 'leave_type_unmapped')->count())->toBe(1)
        ->and(DataIssue::query()->where('type', 'leave_invalid_dates')->count())->toBe(1)
        ->and(ImportMapping::query()->where('header_signature', $sheet['header_signature'])->exists())->toBeTrue();
});

test('older snapshot uploaded later preserves the newer current status', function (): void {
    $newer = leaveSheet([leaveRow('FORM-1', 'Approved')], 'IRAQ All Leave REPORT-2026-10-22-10-00-00.xlsx');
    importLeaveSheet($newer);
    $older = leaveSheet([leaveRow('FORM-1', 'Manager Approval')], 'IRAQ All Leave REPORT-2026-10-20-10-00-00.xlsx');
    importLeaveSheet($older);

    $request = LeaveRequest::query()->where('external_id', 'FORM-1')->firstOrFail();
    expect($request->status)->toBe('approved')
        ->and($request->latest_snapshot_at->format('Y-m-d H:i:s'))->toBe('2026-10-22 10:00:00')
        ->and(RequestVersion::query()->where('request_id', $request->id)->count())->toBe(2);
});

test('a newer snapshot updates one request while preserving the earlier version', function (): void {
    importLeaveSheet(leaveSheet([leaveRow('FORM-1', 'Manager Approval')], 'IRAQ All Leave REPORT-2026-10-20-10-00-00.xlsx'));
    $request = LeaveRequest::query()->where('external_id', 'FORM-1')->firstOrFail();
    expect($request->status)->toBe('pending');

    importLeaveSheet(leaveSheet([leaveRow('FORM-1', 'Approved')], 'IRAQ All Leave REPORT-2026-10-22-10-00-00.xlsx'));
    expect($request->fresh()->status)->toBe('approved')
        ->and(RequestVersion::query()->where('request_id', $request->id)->orderBy('id')->get()->pluck('values')->pluck('status')->all())
        ->toBe(['pending', 'approved']);
});

test('a changed source-only field also preserves a new request version', function (): void {
    importLeaveSheet(leaveSheet([leaveRow('FORM-RAW')], 'IRAQ All Leave REPORT-2026-10-20-10-00-00.xlsx'));
    $changed = leaveRow('FORM-RAW');
    $changed[2] = 'Another Fake Employee';
    importLeaveSheet(leaveSheet([$changed], 'IRAQ All Leave REPORT-2026-10-22-10-00-00.xlsx'));

    $request = LeaveRequest::query()->where('external_id', 'FORM-RAW')->firstOrFail();
    expect(RequestVersion::query()->where('request_id', $request->id)->count())->toBe(2)
        ->and($request->currentVersion->raw_values[2])->toBe('Another Fake Employee');
});

test('re-upload is refused and retry does not duplicate source rows or versions', function (): void {
    $file = leaveSheet([leaveRow('FORM-1')]);
    $batch = importLeaveSheet($file);
    ImportBatchEvent::factory()->create(['batch_id' => $batch->id, 'sequence' => 5, 'state' => 'failed']);
    app()->call([new ImportLeaveJob($batch->id), 'handle']);
    expect(ImportRow::query()->where('batch_id', $batch->id)->count())->toBe(1)
        ->and(RequestVersion::count())->toBe(1);
    $mapping = app(LeaveFile::class)->suggest(app(LeaveFile::class)->inspect($file)['headers']);
    expect(fn () => app(LeaveImport::class)->commit($this->company, $file, $this->actor, $mapping, ['scope' => 'partial'], 'invalid'))
        ->toThrow(ValidationException::class);
});

test('a later full report flags a missing request and a later partial report resolves it', function (): void {
    importLeaveSheet(leaveSheet([leaveRow('FORM-1'), leaveRow('FORM-2')], 'IRAQ All Leave REPORT-2026-10-20-10-00-00.xlsx'));
    importLeaveSheet(leaveSheet([leaveRow('FORM-1')], 'IRAQ All Leave REPORT-2026-10-21-10-00-00.xlsx'), [
        'scope' => 'full', 'period_from' => '2026-10-19', 'period_to' => '2026-10-19',
    ]);
    $request = LeaveRequest::query()->where('external_id', 'FORM-2')->firstOrFail();
    expect($request->status)->toBe('approved')
        ->and(DataIssue::query()->where('type', 'leave_request_missing')->where('status', 'open')->count())->toBe(1);
    importLeaveSheet(leaveSheet([leaveRow('FORM-2')], 'IRAQ All Leave REPORT-2026-10-22-10-00-00.xlsx'));
    expect(DataIssue::query()->where('type', 'leave_request_missing')->where('status', 'open')->count())->toBe(0);
});

test('unknown employee code is retained as text and raised for HR', function (): void {
    $row = leaveRow('FORM-UNKNOWN-EMPLOYEE');
    $row[1] = '00009999';
    importLeaveSheet(leaveSheet([$row]));

    $request = LeaveRequest::query()->where('external_id', 'FORM-UNKNOWN-EMPLOYEE')->firstOrFail();
    expect($request->owner_code)->toBe('00009999')
        ->and($request->employee_id)->toBeNull()
        ->and($request->flags)->toContain('unknown_employee')
        ->and(DataIssue::query()->where('type', 'leave_unknown_employee')->count())->toBe(1)
        ->and(DataIssue::query()->where('type', 'leave_unknown_employee')->firstOrFail()->subject_key)->toBe('owner:00009999');
});

test('unknown employee requests share one bounded issue and resolve after linking', function (): void {
    $rows = [];
    for ($number = 1; $number <= 22; $number++) {
        $row = leaveRow('FORM-'.str_pad((string) $number, 2, '0', STR_PAD_LEFT));
        $row[1] = '00009999';
        $rows[] = $row;
    }
    importLeaveSheet(leaveSheet($rows));
    $issue = DataIssue::query()->where('type', 'leave_unknown_employee')->sole();
    expect($issue->subject_key)->toBe('owner:00009999')
        ->and($issue->details['request_count'])->toBe(22)
        ->and($issue->details['form_numbers'])->toHaveCount(20);

    $employee = app(EmployeeHistory::class)->create(
        ['company_id' => $this->company->id, 'branch_id' => $this->employee->branch_id,
            'name' => 'Fake Later Employee', 'hire_date' => '2020-01-01', 'employee_code' => '00009999'],
        [], '2020-01-01', 'manual', $this->actor,
    );
    expect(LeaveRequest::query()->where('owner_code', '00009999')->where('employee_id', $employee->id)->count())->toBe(22)
        ->and($issue->fresh()->status)->toBe('resolved');
});

test('CSV import keeps a leading-zero employee code and a stable upload snapshot', function (): void {
    $row = leaveRow('FORM-CSV');
    $row[1] = '00001234';
    $csv = implode(',', array_slice(LeaveFile::HEADERS, 2))."\n".implode(',', $row)."\n";
    $file = UploadedFile::fake()->createWithContent('leave-report.csv', $csv);
    $reader = app(LeaveFile::class);
    $first = $reader->inspect($file);
    $second = $reader->inspect($file);
    expect($first['snapshot_at'])->toBe($second['snapshot_at']);

    importLeaveSheet($file);
    expect(LeaveRequest::query()->where('external_id', 'FORM-CSV')->value('owner_code'))->toBe('00001234');
});

test('a mapping saved before the record type was read still picks it up from a sorted header', function (): void {
    $row = leaveRow('FORM-OLD-MAPPING');
    $row[7] = '';
    $headers = LeaveFile::HEADERS;
    $headers[0] .= '  ↑';
    $csv = implode(',', $headers)."\n".implode(',', ['Work From Home', 'An invented note', ...$row])."\n";
    $file = UploadedFile::fake()->createWithContent('leave-report.csv', $csv);
    $reader = app(LeaveFile::class);
    $sheet = $reader->inspect($file);
    $saved = array_filter($reader->suggest($sheet['headers']), fn (string $field): bool => ! in_array($field, ['record_type', 'description'], true));

    expect(iterator_to_array($reader->rows($file, $saved))[0]['errors'])->toContain('type_name_required');

    $parsed = iterator_to_array($reader->rows($file, $reader->withNewColumns($saved, $sheet['headers'])))[0];
    expect($parsed['errors'])->not->toContain('type_name_required')
        ->and($parsed['values']['type_name'])->toBe('Work From Home')
        ->and($parsed['values']['description'])->toBe('An invented note');
});

test('an HR user cannot preview a leave report for another company', function (): void {
    $other = Company::factory()->create();
    $file = leaveSheet([leaveRow('FORM-1')]);
    $reader = app(LeaveFile::class);
    $mapping = $reader->suggest($reader->inspect($file)['headers']);

    expect(fn () => app(LeaveImport::class)->preview($other, $file, $mapping, ['scope' => 'partial']))
        ->toThrow(HttpException::class);
});

function leaveDescriptionSheet(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'leave-descriptions-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(LeaveFile::HEADERS));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, 'IRAQ All Leave REPORT-2026-11-04-10-00-00.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

test('new report fills record type, skips summaries and leaves old reports unchanged', function (): void {
    $first = leaveRow('FORM-WFH', type: '');
    $second = leaveRow('FORM-WFH-2', type: '');
    $travel = leaveRow('FORM-TRAVEL', type: '');
    $admin = leaveRow('FORM-ADMIN', type: '');
    $file = leaveDescriptionSheet([
        ['Work From Home', 'Working from home', ...$first],
        ['', 'Working from home again', ...$second],
        ['Subtotal'],
        ['Business Travel Request', 'Site visit', ...$travel],
        ['Total'],
        ['Administrative Leave', 'Family reason', ...$admin],
    ]);
    $reader = app(LeaveFile::class);
    $mapping = $reader->suggest($reader->inspect($file)['headers']);
    $rows = iterator_to_array($reader->rows($file, $mapping));
    expect($rows)->toHaveCount(4)
        ->and($rows[1]['values']['record_type'])->toBe('Work From Home')
        ->and($rows[1]['values']['type_name'])->toBe('Work From Home')
        ->and($rows[1]['values']['description'])->toBe('Working from home again');
    $batch = importLeaveSheet($file);
    expect(ImportRow::query()->where('batch_id', $batch->id)->count())->toBe(4)
        ->and(LeaveType::query()->where('normalized_key', 'work from home|')->firstOrFail()->covers)->toBeTrue()
        ->and(LeaveType::query()->where('normalized_key', 'business travel request|')->firstOrFail()->covers)->toBeTrue()
        ->and(LeaveType::query()->where('normalized_key', 'administrative leave|')->firstOrFail()->is_mapped)->toBeFalse();
    $old = leaveSheet([leaveRow('FORM-OLD-SHAPE')], 'IRAQ All Leave REPORT-2026-11-05-10-00-00.xlsx');
    $oldRow = iterator_to_array($reader->rows($old, $reader->suggest($reader->inspect($old)['headers'])))[0];
    expect($oldRow['values'])->not->toHaveKey('description')->not->toHaveKey('record_type');
    $preview = app(LeaveImport::class)->preview($this->company, $file, $mapping, ['scope' => 'partial']);
    expect($preview['already_imported'])->toBe($batch->id);
});

test('a sort arrow on a report column does not hide the column', function (): void {
    expect(app(LeaveFile::class)->suggest(['MyForm: Record Type  ↑', 'Leave Status ↓', 'Leave Description']))
        ->toBe(['record_type', 'raw_status', 'description']);
});

test('backfill appends one correction version and changes only missing description and type', function (): void {
    $row = leaveRow('FORM-BACKFILL', type: '');
    $file = leaveDescriptionSheet([['Work From Home', 'An invented home-work note', ...$row]]);
    $reader = app(LeaveFile::class);
    $mapping = $reader->suggest($reader->inspect($file)['headers']);
    $mapping = array_filter($mapping, fn (string $field): bool => ! in_array($field, ['record_type', 'description'], true));
    $import = app(LeaveImport::class);
    $preview = $import->preview($this->company, $file, $mapping, ['scope' => 'partial']);
    $batch = $import->commit($this->company, $file, $this->actor, $mapping, ['scope' => 'partial'], $preview['token']);
    app()->call([new ImportLeaveJob($batch->id), 'handle']);
    $request = LeaveRequest::query()->where('external_id', 'FORM-BACKFILL')->firstOrFail();
    $before = $request->currentVersion;
    expect($before->values)->not->toHaveKey('description');
    Artisan::call('attendance:leave-descriptions-backfill', ['--company' => $this->company->id]);
    $request->refresh();
    expect($request->currentVersion->values['description'])->toBe('An invented home-work note')
        ->and($request->currentVersion->values['type_name'])->toBe('Work From Home')
        ->and($request->leaveType->name)->toBe('Work From Home')
        ->and(RequestVersion::query()->where('request_id', $request->id)->count())->toBe(2)
        ->and($before->fresh()->values)->not->toHaveKey('description');
    Artisan::call('attendance:leave-descriptions-backfill', ['--company' => $this->company->id]);
    expect(RequestVersion::query()->where('request_id', $request->id)->count())->toBe(2);
    $later = leaveDescriptionSheet([['Work From Home', 'An invented home-work note', ...$row], ['Subtotal']]);
    $laterMapping = $reader->suggest($reader->inspect($later)['headers']);
    $laterPreview = $import->preview($this->company, $later, $laterMapping, ['scope' => 'partial']);
    expect($laterPreview['counts']['unchanged'])->toBe(1)
        ->and($laterPreview['counts']['changed'])->toBe(0);
});
