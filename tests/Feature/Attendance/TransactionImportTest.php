<?php

use App\Enums\UserRole;
use App\Jobs\Attendance\ImportTransactionsJob;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Device;
use App\Models\Attendance\ImportBatchEvent;
use App\Models\Attendance\Person;
use App\Models\Attendance\Transaction;
use App\Models\Attendance\TransactionSource;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\DataIssues;
use App\Services\Attendance\DeviceRegistry;
use App\Services\Attendance\PersonClassifier;
use App\Services\Attendance\TransactionFile;
use App\Services\Attendance\TransactionImport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpKernel\Exception\HttpException;

function attendanceSheet(array $rows, string $name = 'Transaction_20261020084331.xlsx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'attendance-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Transaction']));
    $writer->addRow(Row::fromValues(['Employee ID', 'First Name', 'Department', 'Date', 'Time', 'Punch State', 'Serial Number', 'Device Name', 'Data Sources']));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function attendanceImportJob(int $batchId): void
{
    app()->call([new ImportTransactionsJob($batchId), 'handle']);
}

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['name' => 'Hilla']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->employee = Employee::factory()->create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'employee_code' => '12345678']);
    $this->actingAs($this->actor);
});

test('title row, numeric code and export time produce a bounded preview without writes', function (): void {
    $file = attendanceSheet([
        [12345678, 'Fake Employee', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
        [12345678, 'Fake Employee', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
        [12345678, 'Fake Employee', 'Operations', '2026-10-20', '08:05', '255', 'SER-2', 'Hilla_RESTARRANT_Face', 'Face'],
        ['0033', 'Fake Contractor', '3rd Party Site', '2026-10-20', '08:10', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
        ['44', 'Fake Guest', 'Guest', '2026-10-20', '08:11', '255', 'SER-2', 'Hilla_RESTARRANT_Face', 'Face'],
        ['99999999', 'Fake Unknown', 'Operations', '2026-10-20', '08:12', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
    ]);
    $reader = app(TransactionFile::class);
    $sheet = $reader->inspect($file);
    expect($sheet['header_row'])->toBe(2)->and($sheet['export_at'])->toBe('2026-10-20 08:43:31');
    $mapping = $reader->suggest($sheet['headers']);
    $rows = iterator_to_array($reader->rows($file, $mapping));
    expect($rows[0]['number'])->toBe(3)->and($rows[0]['raw']['person_code'])->toBe('12345678')
        ->and($rows[3]['raw']['person_code'])->toBe('0033');

    $preview = app(TransactionImport::class)->preview($this->company, $file, $mapping, ['branch_ids' => [$this->branch->id]]);
    expect($preview['counts']['rows'])->toBe(6)->and($preview['counts']['duplicate_in_file'])->toBe(1)
        ->and($preview['counts']['restaurant'])->toBe(2)->and($preview['counts']['unknown_ids'])->toBe(1)
        ->and($preview['counts']['non_employee_ids'])->toBe(2)
        ->and($preview['suggested_coverage']['to'])->toBe('2026-10-20 08:43:31')
        ->and(Transaction::count())->toBe(0)->and(Device::count())->toBe(0);
});

test('CSV and Excel date and time serials are read without changing source text', function (): void {
    $csv = UploadedFile::fake()->createWithContent('Transaction_20261020084331.csv', "Transaction\nEmployee ID,First Name,Department,Date,Time,Punch State,Serial Number,Device Name,Data Sources\n00001234,Fake Person,Operations,2026-10-20,08:05,Check In,SER-1,Hilla_In_Face,Face\n");
    $reader = app(TransactionFile::class);
    $mapping = $reader->suggest($reader->inspect($csv)['headers']);
    $rows = iterator_to_array($reader->rows($csv, $mapping));
    expect($rows[0]['raw']['person_code'])->toBe('00001234')
        ->and($rows[0]['punched_at'])->toBe('2026-10-20 08:05:00');

    $excel = attendanceSheet([[12345678, 'Fake Person', 'Operations', 39151, 0.5, 'Check In', 'SER-1', 'Hilla_In_Face', 'Face']]);
    $excelMapping = $reader->suggest($reader->inspect($excel)['headers']);
    $excelRows = iterator_to_array($reader->rows($excel, $excelMapping));
    expect($excelRows[0]['values']['punch_time'])->toBe('12:00')
        ->and($excelRows[0]['raw']['punch_date'])->toBe('39151');
});

test('preview retains bounded error examples while counting every row', function (): void {
    config(['attendance.preview_sample_limit' => 2]);
    $rows = [[12345678, 'Fake Employee', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face']];
    for ($minute = 1; $minute <= 5; $minute++) {
        $rows[] = [12345678, 'Fake Employee', 'Operations', '2026-10-20', 'bad-'.$minute, 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'];
    }
    $file = attendanceSheet($rows);
    $mapping = app(TransactionFile::class)->suggest(app(TransactionFile::class)->inspect($file)['headers']);
    $preview = app(TransactionImport::class)->preview($this->company, $file, $mapping, ['branch_ids' => [$this->branch->id]]);
    expect($preview['counts']['rows'])->toBe(6)->and($preview['counts']['errors'])->toBe(5)
        ->and($preview['samples']['errors'])->toHaveCount(2);
});

test('an HR user cannot preview another company attendance file', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->runForCompany($other, fn () => Branch::factory()->create(['company_id' => $other->id, 'name' => 'Hilla']));
    $file = attendanceSheet([[12345678, 'Fake Employee', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face']]);
    $mapping = app(TransactionFile::class)->suggest(app(TransactionFile::class)->inspect($file)['headers']);

    expect(fn () => app(TransactionImport::class)->preview($other, $file, $mapping, []))
        ->toThrow(HttpException::class);
});

test('a pending device suggests role and site from its name when the state is unknown', function (): void {
    $device = app(DeviceRegistry::class)->register($this->company, 'SER-NEW', 'Hilla_RESTARRANT_Face', 'unrecognised');
    expect($device->status)->toBe('pending')->and($device->suggested_role)->toBe('restaurant')
        ->and($device->suggested_branch_id)->toBe($this->branch->id)
        ->and(DataIssue::query()->where('type', 'device_pending')->count())->toBe(1);
});

test('data issues keep one open issue per subject and allow a later occurrence', function (): void {
    $issues = app(DataIssues::class);
    $first = $issues->open($this->company, 'unknown_person', 'person', '99999999');
    expect($issues->open($this->company, 'unknown_person', 'person', '99999999')->id)->toBe($first->id);
    $issues->resolve($first, $this->actor, 'Matched to an employee.');
    $later = $issues->open($this->company, 'unknown_person', 'person', '99999999');
    expect($later->id)->not->toBe($first->id);
    $issues->dismiss($later, $this->actor, 'Confirmed visitor.');
    expect(DataIssue::query()->where('subject_key', '99999999')->count())->toBe(2)
        ->and(DataIssue::query()->whereNotNull('open_key')->count())->toBe(0);
});

test('commit preserves every source row and retry does not duplicate transactions or issues', function (): void {
    $file = attendanceSheet([
        [12345678, 'Fake Employee', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
        [12345678, ' Different Source Name ', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
        ['44', 'Fake Guest', 'Guest', '2026-10-20', '08:11', '255', 'SER-2', 'Hilla_RESTAURANT_Face', 'Face'],
        ['99999999', 'Fake Unknown', 'Operations', '2026-10-20', '08:12', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
    ]);
    $import = app(TransactionImport::class);
    $mapping = app(TransactionFile::class)->suggest(app(TransactionFile::class)->inspect($file)['headers']);
    $coverage = ['branch_ids' => [$this->branch->id]];
    $preview = $import->preview($this->company, $file, $mapping, $coverage);
    $batch = $import->commit($this->company, $file, $this->actor, $mapping, $coverage, $preview['token']);
    expect(DB::table('attendance_coverage_intervals')->where('batch_id', $batch->id)->value('is_confirmed'))->toBeFalsy();

    attendanceImportJob($batch->id);
    attendanceImportJob($batch->id);
    expect(Transaction::count())->toBe(3)->and(TransactionSource::count())->toBe(4)
        ->and(TransactionSource::query()->where('duplicate_in_file', true)->value('person_name'))->toBe(' Different Source Name ')
        ->and(Device::count())->toBe(2)->and(Person::count())->toBe(3)
        ->and(DataIssue::query()->where('type', 'unknown_person')->count())->toBe(1)
        ->and(DataIssue::query()->where('type', 'device_pending')->count())->toBe(2)
        ->and(ImportBatchEvent::query()->where('batch_id', $batch->id)->where('state', 'completed')->count())->toBe(1);
    $restaurant = Device::query()->where('serial_number', 'SER-2')->firstOrFail();
    expect($restaurant->status)->toBe('pending')->and($restaurant->suggested_role)->toBe('restaurant')
        ->and($restaurant->suggested_branch_id)->toBe($this->branch->id)
        ->and(Person::query()->where('person_code', '44')->value('kind'))->toBe('non_employee')
        ->and(Person::query()->where('person_code', '99999999')->value('kind'))->toBe('unknown');
    $sequences = ImportBatchEvent::query()->where('batch_id', $batch->id)->orderBy('sequence')->pluck('sequence')->all();
    expect($sequences)->toBe(range(1, count($sequences)));
    expect(fn () => $import->commit($this->company, $file, $this->actor, $mapping, $coverage, $preview['token']))->toThrow(ValidationException::class);
});

test('overlapping files retain one canonical punch and separate raw source rows', function (): void {
    $first = attendanceSheet([[12345678, 'Fake Employee', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face']]);
    $import = app(TransactionImport::class);
    $mapping = app(TransactionFile::class)->suggest(app(TransactionFile::class)->inspect($first)['headers']);
    $coverage = ['branch_ids' => [$this->branch->id]];
    $one = $import->preview($this->company, $first, $mapping, $coverage);
    $batchOne = $import->commit($this->company, $first, $this->actor, $mapping, $coverage, $one['token']);
    attendanceImportJob($batchOne->id);

    $second = attendanceSheet([
        [12345678, 'Updated Export Name', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
        [12345678, 'Fake Employee', 'Operations', '2026-10-20', '17:00', 'Check Out', 'SER-3', 'Hilla_Out_Face', 'Face'],
    ], 'Transaction_20261020180000.xlsx');
    $two = $import->preview($this->company, $second, $mapping, $coverage);
    expect($two['counts']['existing'])->toBe(1);
    $batchTwo = $import->commit($this->company, $second, $this->actor, $mapping, $coverage, $two['token']);
    attendanceImportJob($batchTwo->id);
    expect(Transaction::count())->toBe(2)->and(TransactionSource::count())->toBe(3)
        ->and(TransactionSource::query()->where('batch_id', $batchTwo->id)->where('punch_time', '08:00')->value('person_name'))->toBe('Updated Export Name');
});

test('invalid rows remain traceable and a failed chunk can be retried without duplication', function (): void {
    $file = attendanceSheet([
        [12345678, 'Fake Employee', 'Operations', '2026-10-20', '08:00', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
        [12345678, 'Fake Employee', 'Operations', '2026-10-20', 'not-a-time', 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'],
    ]);
    $import = app(TransactionImport::class);
    $mapping = app(TransactionFile::class)->suggest(app(TransactionFile::class)->inspect($file)['headers']);
    $coverage = ['branch_ids' => [$this->branch->id]];
    $preview = $import->preview($this->company, $file, $mapping, $coverage);
    expect($preview['counts']['errors'])->toBe(1);
    $batch = $import->commit($this->company, $file, $this->actor, $mapping, $coverage, $preview['token']);
    $flaky = Mockery::mock(PersonClassifier::class);
    $flaky->shouldReceive('classify')->once()->andThrow(new RuntimeException('Simulated worker interruption.'));
    $job = new ImportTransactionsJob($batch->id);
    expect(fn () => $job->handle(app(TransactionFile::class), $import, app(DeviceRegistry::class), $flaky, app(DataIssues::class)))
        ->toThrow(RuntimeException::class);
    expect(TransactionSource::count())->toBe(0);
    attendanceImportJob($batch->id);
    expect(Transaction::count())->toBe(1)->and(TransactionSource::count())->toBe(2)
        ->and(TransactionSource::query()->whereNull('transaction_id')->count())->toBe(1)
        ->and(DataIssue::query()->where('type', 'import_row_error')->count())->toBe(1)
        ->and(ImportBatchEvent::query()->where('batch_id', $batch->id)->where('state', 'failed')->count())->toBe(1);
});

test('a retry resumes after a committed chunk without duplicating source rows', function (): void {
    $rows = [];
    $start = new DateTimeImmutable('2026-10-18 08:00');
    for ($index = 0; $index < 1001; $index++) {
        $at = $index === 1000 ? $start : $start->modify('+'.$index.' minutes');
        $rows[] = [12345678, 'Fake Employee', 'Operations', $at->format('Y-m-d'), $at->format('H:i'), 'Check In', 'SER-1', 'Hilla_In_Face', 'Face'];
    }
    $file = attendanceSheet($rows);
    $import = app(TransactionImport::class);
    $mapping = app(TransactionFile::class)->suggest(app(TransactionFile::class)->inspect($file)['headers']);
    $coverage = ['branch_ids' => [$this->branch->id]];
    $preview = $import->preview($this->company, $file, $mapping, $coverage);
    $batch = $import->commit($this->company, $file, $this->actor, $mapping, $coverage, $preview['token']);
    $real = app(DeviceRegistry::class);
    $calls = 0;
    $flaky = Mockery::mock(DeviceRegistry::class);
    $flaky->shouldReceive('register')->andReturnUsing(function (...$arguments) use ($real, &$calls) {
        $calls++;
        if ($calls === 2) {
            throw new RuntimeException('Simulated second-chunk interruption.');
        }

        return $real->register(...$arguments);
    });
    expect(fn () => (new ImportTransactionsJob($batch->id))->handle(app(TransactionFile::class), $import, $flaky, app(PersonClassifier::class), app(DataIssues::class)))
        ->toThrow(RuntimeException::class);
    expect(TransactionSource::count())->toBe(1000);
    attendanceImportJob($batch->id);
    expect(Transaction::count())->toBe(1000)->and(TransactionSource::count())->toBe(1001)
        ->and(TransactionSource::query()->where('row_number', 1003)->value('duplicate_in_file'))->toBeTruthy()
        ->and(ImportBatchEvent::query()->where('batch_id', $batch->id)->where('state', 'completed')->firstOrFail()->counts['duplicate_in_file'])->toBe(1)
        ->and(ImportBatchEvent::query()->where('batch_id', $batch->id)->where('state', 'completed')->count())->toBe(1);
});
