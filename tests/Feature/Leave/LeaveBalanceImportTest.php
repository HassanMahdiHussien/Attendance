<?php

use App\Enums\UserRole;
use App\Jobs\Leave\ImportLeaveBalanceJob;
use App\Models\Attendance\DataIssue;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Leave\Balance;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceBatchEvent;
use App\Models\Leave\BalanceMapping;
use App\Models\Leave\BalanceRow;
use App\Models\User;
use App\Services\Leave\LeaveBalanceFile;
use App\Services\Leave\LeaveBalanceImport;
use App\Services\Leave\LeaveBalances;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpKernel\Exception\HttpException;

function balanceSheet(array $rows, bool $cached = true): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'balance-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Pers.No.', 'REMAINING FROM 2025',
        'ANNUAL LEAVE DAYS EMPLOYEE IS/TO BE ENTITELED IN 2026', 'YTD Act Used (Actual)',
        'Total Remaining Annual Leave Days', 'January 2026', 'February 2026']));
    $writer->addRow(Row::fromValues(['Employee Group', '', '', '', '', 'ACT', 'PLAN']));
    foreach ($rows as $row) {
        $writer->addRow(new Row(array_map(fn ($value) => $value instanceof FormulaCell ? $value : Cell::fromValue($value), $row)));
    }
    $writer->close();
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($cached) {
        $xml = preg_replace('/(<c r="E3"[^>]*><f>[^<]*<\/f>)(<\/c>)/', '$1<v>3.8333</v>$2', $xml);
    }
    $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
    $zip->close();

    return new UploadedFile($path, 'Iraq Annual Leave 2026.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

function commitBalance(UploadedFile $file, string $date): BalanceBatch
{
    $company = Company::findOrFail(app(TenantContext::class)->companyId());
    $reader = app(LeaveBalanceFile::class);
    $sheet = $reader->inspect($file);
    $mapping = $reader->suggest($sheet['headers'], $sheet['subheaders']);
    $service = app(LeaveBalanceImport::class);
    $preview = $service->preview($company, $file, $mapping, $date, 2026);
    $batch = $service->commit($company, $file, auth()->user(), $mapping, $date, 2026, $preview['token'] ?? '');
    app()->call([new ImportLeaveBalanceJob($batch->id), 'handle']);

    return $batch;
}

beforeEach(function (): void {
    Queue::fake();
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->employee = Employee::factory()->create(['employee_code' => '12345678']);
    $this->otherEmployee = Employee::factory()->create(['employee_code' => '87654321']);
    $this->actingAs($this->actor);
});

test('reader uses cached formula values and the ACT subheader', function (): void {
    $file = balanceSheet([[12345678, 1.5, 6, 2.1667, new FormulaCell('=B3+C3-D3', null), 2.1667, null]]);
    $reader = app(LeaveBalanceFile::class);
    $sheet = $reader->inspect($file);
    $mapping = $reader->suggest($sheet['headers'], $sheet['subheaders']);
    $row = iterator_to_array($reader->rows($file, $mapping, codeLength: 8), false)[0];

    expect($sheet['header_row'])->toBe(1)
        ->and($sheet['suggested_year'])->toBe(2026)
        ->and($mapping[5])->toBe('used_01')
        ->and($row['values']['employee_code'])->toBe('12345678')
        ->and($row['values']['remaining_days'])->toBe('3.8333')
        ->and($row['values']['used_01'])->toBe('2.1667');
});

test('preview, import, retry and dated history preserve valid rows and skipped reasons', function (): void {
    $file = balanceSheet([
        [12345678, 1.5, 6, 2.1667, new FormulaCell('=B3+C3-D3', null), 2.1667, null],
        [87654321, 0, 1, 4.5, -3.5, 4.5, null],
        [99999999, 0, 1, 0, 1, 0, null],
        [99999998, 0, 1, 0, 1, 0, null],
        [12345678, 0, 1, 0, 1, 0, null],
        [1234, 0, 1, 0, 1, 0, null],
        [22222222, 0, 1, 0, new FormulaCell('=B8+C8-D8', null), 0, null],
    ]);
    $reader = app(LeaveBalanceFile::class);
    $sheet = $reader->inspect($file);
    $mapping = $reader->suggest($sheet['headers'], $sheet['subheaders']);
    $preview = app(LeaveBalanceImport::class)->preview($this->company, $file, $mapping, '2026-10-20', 2026);
    expect($preview['counts']['rows'])->toBe(7)
        ->and($preview['counts']['matched'])->toBe(2)
        ->and($preview['counts']['unknown'])->toBe(2)
        ->and($preview['counts']['duplicate'])->toBe(1)
        ->and($preview['counts']['invalid'])->toBe(2)
        ->and($preview['counts']['negative'])->toBe(1);
    $batch = commitBalance($file, '2026-10-20');
    app()->call([new ImportLeaveBalanceJob($batch->id), 'handle']);

    expect(Balance::query()->where('batch_id', $batch->id)->count())->toBe(2)
        ->and(BalanceRow::query()->where('batch_id', $batch->id)->count())->toBe(7)
        ->and(BalanceRow::query()->where('reason_code', 'formula_without_value')->count())->toBe(1)
        ->and(DataIssue::query()->where('type', 'leave_balance_unknown')->count())->toBe(1)
        ->and(DataIssue::query()->where('type', 'leave_balance_unknown')->firstOrFail()->details['count'])->toBe(2)
        ->and(BalanceBatchEvent::query()->where('batch_id', $batch->id)->orderByDesc('sequence')->value('state'))->toBe('completed')
        ->and(BalanceMapping::query()->where('header_signature', $sheet['header_signature'])->exists())->toBeTrue()
        ->and(app(LeaveBalances::class)->current($this->employee)?->remaining_days)->toBe('3.8333')
        ->and(app(LeaveBalances::class)->presentInLatestUpload($this->employee))->toBeTrue();

    expect(app(LeaveBalanceImport::class)->commit($this->company, $file, $this->actor, $mapping, '2026-10-20', 2026, $preview['token'])->id)->toBe($batch->id)
        ->and(Balance::count())->toBe(2);
    $newer = commitBalance($file, '2026-10-21');
    expect($newer->id)->not->toBe($batch->id)
        ->and(app(LeaveBalances::class)->history($this->employee))->toHaveCount(2);
    commitBalance($file, '2026-10-19');
    expect(app(LeaveBalances::class)->current($this->employee)?->balance_date->toDateString())->toBe('2026-10-21');
});

test('another company cannot preview a balance report', function (): void {
    $other = Company::factory()->create();
    $otherEmployee = app(TenantContext::class)->runForCompany($other, fn () => Employee::factory()->create());
    app(TenantContext::class)->setCompany($this->company);
    $file = balanceSheet([[12345678, 0, 1, 0, 1, 0, null]]);
    $sheet = app(LeaveBalanceFile::class)->inspect($file);
    $mapping = app(LeaveBalanceFile::class)->suggest($sheet['headers'], $sheet['subheaders']);

    expect(fn () => app(LeaveBalanceImport::class)->preview($other, $file, $mapping, '2026-10-20', 2026))
        ->toThrow(HttpException::class)
        ->and(fn () => app(LeaveBalances::class)->current($otherEmployee))->toThrow(HttpException::class);
});

test('an absent employee keeps their last balance but is missing from the latest completed upload', function (): void {
    commitBalance(balanceSheet([[12345678, 0, 10, 1, 9, 1, null], [87654321, 0, 10, 1, 9, 1, null]]), '2026-10-20');
    $newer = balanceSheet([[87654321, 0, 10, 2, 8, 2, null]]);
    $sheet = app(LeaveBalanceFile::class)->inspect($newer);
    $mapping = app(LeaveBalanceFile::class)->suggest($sheet['headers'], $sheet['subheaders']);
    $preview = app(LeaveBalanceImport::class)->preview($this->company, $newer, $mapping, '2026-10-21', 2026);
    expect($preview['counts']['missing'])->toBe(1);
    commitBalance($newer, '2026-10-21');

    expect(app(LeaveBalances::class)->current($this->employee)?->remaining_days)->toBe('9.0000')
        ->and(app(LeaveBalances::class)->presentInLatestUpload($this->employee))->toBeFalse()
        ->and(app(LeaveBalances::class)->presentInLatestUpload($this->otherEmployee))->toBeTrue();
});

test('CSV employee codes remain exact text including leading zeroes', function (): void {
    $employee = Employee::factory()->create(['employee_code' => '00001234']);
    $file = UploadedFile::fake()->createWithContent('annual.csv', "Annual leave report\nPers.No.,TOTAL  remaining ANNUAL Leave Days\n00001234,1.25\n");
    expect(app(LeaveBalanceFile::class)->inspect($file)['header_row'])->toBe(2);
    $batch = commitBalance($file, '2026-10-20');

    expect(Balance::query()->where('batch_id', $batch->id)->where('employee_id', $employee->id)->value('remaining_days'))->toBe('1.2500');
});

test('the first valid occurrence wins after an invalid earlier row', function (): void {
    $file = balanceSheet([
        [12345678, 0, 2, 0, new FormulaCell('=B3+C3-D3', null), 0, null],
        [12345678, 0, 2, 0, 2, 0, null],
        [12345678, 0, 3, 0, 3, 0, null],
    ], cached: false);
    $batch = commitBalance($file, '2026-10-20');

    expect(BalanceRow::query()->where('batch_id', $batch->id)->orderBy('row_number')->pluck('outcome')->all())
        ->toBe(['invalid', 'imported', 'duplicate'])
        ->and(Balance::query()->where('batch_id', $batch->id)->firstOrFail()->remaining_days)->toBe('2.0000');
});

test('a later completed upload on the same balance date is current', function (): void {
    commitBalance(balanceSheet([[12345678, 0, 2, 0, 2, 0, null]]), '2026-10-20');
    $second = commitBalance(balanceSheet([[12345678, 0, 4, 0, 4, 0, null]]), '2026-10-20');

    expect(app(LeaveBalances::class)->current($this->employee)?->batch_id)->toBe($second->id)
        ->and(app(LeaveBalances::class)->history($this->employee)[0]['change_days'])->toBe('2.0000');
});

test('mapping and future dates are refused before a batch is created', function (): void {
    $file = balanceSheet([[12345678, 0, 2, 0, 2, 0, null]]);
    $reader = app(LeaveBalanceFile::class);
    $sheet = $reader->inspect($file);
    $mapping = $reader->suggest($sheet['headers'], $sheet['subheaders']);

    expect(fn () => app(LeaveBalanceImport::class)->preview($this->company, $file, [0 => 'employee_code'], '2026-10-20', 2026))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(LeaveBalanceImport::class)->preview($this->company, $file, $mapping, '2099-01-01', 2026))
        ->toThrow(ValidationException::class)
        ->and(BalanceBatch::count())->toBe(0);
});

test('a confirmation token cannot be reused with another balance date or year', function (): void {
    $file = balanceSheet([[12345678, 0, 2, 0, 2, 0, null]]);
    $sheet = app(LeaveBalanceFile::class)->inspect($file);
    $mapping = app(LeaveBalanceFile::class)->suggest($sheet['headers'], $sheet['subheaders']);
    $preview = app(LeaveBalanceImport::class)->preview($this->company, $file, $mapping, '2026-10-20', 2026);

    expect(fn () => app(LeaveBalanceImport::class)->commit($this->company, $file, $this->actor, $mapping, '2026-10-21', 2026, $preview['token']))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(LeaveBalanceImport::class)->commit($this->company, $file, $this->actor, $mapping, '2026-10-20', 2025, $preview['token']))
        ->toThrow(ValidationException::class)
        ->and(BalanceBatch::count())->toBe(0);
});
