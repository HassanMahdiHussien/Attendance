<?php

use App\Enums\EmployeeExitStatus;
use App\Enums\UserRole;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportMapping;
use App\Models\EmployeeVersion;
use App\Models\User;
use App\Services\Branches\BranchMatcher;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeHistory;
use App\Services\Employees\EmployeeImport;
use App\Services\Employees\SpreadsheetReader;
use App\Support\Tenancy\TenantContext;
use DateTimeInterface;
use Filament\Facades\Filament;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

function hcHeaders(): array
{
    return ['Pers.No.', 'Personnel Number', '', 'Position', 'Entry', 'Employee Group', 'Employee Subgroup',
        'Payroll Area', 'Personnel Subarea', 'Directorate', 'AG HR Collar', 'Age of employee', 'Birth date',
        'Gender Key', 'Nationality', 'Organizational Unit', 'Company Code', 'Educational establishment',
        'Certificate', 'Pers.No.', 'Personnel number of superior (', 'Cost Ctr', 'Cost Center', 'Personnel Area'];
}

function hcRow(int|string $code, string $englishName, mixed $arabicName, int|DateTimeInterface $entry, string $managerCode = '', string $category = '', string $entity = ''): array
{
    $row = array_fill(0, 24, '');
    $row[0] = $code;
    $row[1] = $englishName;
    $row[2] = $arabicName;
    $row[3] = 'Analyst';
    $row[4] = $entry;
    $row[10] = $category;
    $row[13] = 'Female';
    $row[15] = 'Operations';
    $row[16] = $entity;
    $row[19] = $managerCode;
    $row[20] = $managerCode === '' ? '' : 'Fake Manager';
    $row[23] = 'Hilla';

    return $row;
}

function hcFile(array $rows, bool $errorCell = false): File
{
    $path = tempnam(sys_get_temp_dir(), 'employee-hc-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(hcHeaders()));
    foreach ($rows as $row) {
        $writer->addRow(Row::fromValues($row));
    }
    $writer->close();
    if ($errorCell) {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $xml = preg_replace('/<c\b[^>]*\br="C2"[^>]*>.*?<\/c>/s', '<c r="C2" t="e"><v>#N/A</v></c>', $xml, 1, $replaced);
        if ($replaced !== 1) {
            throw new RuntimeException('The generated spreadsheet has no C2 cell.');
        }
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
    }

    $file = UploadedFile::fake()->createWithContent('HC.xlsx', file_get_contents($path));
    unlink($path);

    return $file;
}

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    $this->branch = Branch::factory()->create(['name' => 'Hilla']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('HC headers map by position and numeric codes and dates survive preview and commit', function (): void {
    $file = hcFile([
        hcRow(12345678, 'Fake Person', '#N/A', 39151, '87654321', 'B', 'Al Waha Company'),
        hcRow(87654321, 'Fake Manager', 'اسم تجريبي', new DateTimeImmutable('2008-04-05'), '', 'W', 'Sardkar for Bvr. Ind/Ltd'),
    ], true);
    $sheet = app(SpreadsheetReader::class)->read($file);
    $mapping = app(ColumnMapping::class)->suggest($sheet['headers']);
    expect($mapping[0])->toBe('employee_code')->and($mapping[2])->toBe('name')
        ->and($mapping[10])->toBe('staff_category')->and($mapping[16])->toBe('legal_entity')
        ->and($mapping[19])->toBe('manager_code')->and($mapping[20])->toBe('manager_name')
        ->and($sheet['headers'][2])->toBe('');
    $import = app(EmployeeImport::class);
    $preview = $import->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping);
    expect($preview->errors())->toBe(0)->and($preview->added())->toBe(2)
        ->and($preview->rows[0]['code'])->toBe('12345678')
        ->and($preview->rows[0]['name'])->toBe('Fake Person')
        ->and($preview->rows[0]['values']['hire_date'])->toBe('2007-03-10')
        ->and($preview->rows[0]['values']['staff_category'])->toBe('B')
        ->and($preview->rows[0]['values']['legal_entity'])->toBe('Al Waha Company')
        ->and($preview->rows[0]['diff']['work_pattern']['after'])->toBe('shift')
        ->and($preview->rows[0]['resolved_manager_code'])->toBe('87654321');
    $result = $import->commitMapped($this->company, $file, $this->actor, 'full', today()->toDateString(), $mapping, $preview->token);
    $person = Employee::query()->where('employee_code', '12345678')->firstOrFail();
    $manager = Employee::query()->where('employee_code', '87654321')->firstOrFail();
    expect($result['committed'])->toBeTrue()->and($person->manager_employee_id)->toBe($manager->id)
        ->and($person->name_en)->toBe('Fake Person')
        ->and($person->staff_category)->toBe('B')
        ->and($person->legal_entity)->toBe('Al Waha Company')
        ->and($person->work_pattern?->value)->toBe('shift')
        ->and($manager->work_pattern?->value)->toBe('office')
        ->and(app(EmployeeHistory::class)->asOf($person, today())['legal_entity'])->toBe('Al Waha Company')
        ->and(app(EmployeeHistory::class)->asOf($person, today())['staff_category'])->toBe('B')
        ->and(EmployeeImportBatch::query()->count())->toBe(1)
        ->and(EmployeeImportMapping::query()->count())->toBe(1)
        ->and(EmployeeVersion::query()->where('employee_id', $person->id)->where('employee_import_batch_id', $result['batch']->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($result['batch']->file_path))->toBeTrue();
    expect(app(ColumnMapping::class)->saved($this->company, hcHeaders()))->toBe($mapping);
    $repeat = $import->previewMapped($this->company, $file, 'partial', '2025-01-01', $mapping);
    expect($repeat->alreadyImported)->not->toBeNull()->and($repeat->token)->toBeNull();
    expect($import->commitMapped($this->company, $file, $this->actor, 'partial', '2025-01-01', $mapping, $preview->token)['committed'])->toBeFalse();
});

test('a later HC import cannot replace an HR work pattern', function (): void {
    $import = app(EmployeeImport::class);
    $mapping = app(ColumnMapping::class)->suggest(hcHeaders());
    $first = hcFile([hcRow(12345678, 'Fake Person', '', 39151, '', 'B', 'Al Waha Company')]);
    $preview = $import->previewMapped($this->company, $first, 'partial', today()->toDateString(), $mapping);
    $import->commitMapped($this->company, $first, $this->actor, 'partial', today()->toDateString(), $mapping, $preview->token);
    $employee = Employee::query()->where('employee_code', '12345678')->firstOrFail();
    app(EmployeeHistory::class)->record($employee, ['work_pattern' => 'security'], today(), 'manual', $this->actor);

    $later = hcFile([hcRow(12345678, 'Fake Person', '', 39151, '', 'W', 'Al Waha Company')]);
    $next = $import->previewMapped($this->company, $later, 'partial', today()->toDateString(), $mapping);
    $import->commitMapped($this->company, $later, $this->actor, 'partial', today()->toDateString(), $mapping, $next->token);

    expect($employee->fresh()->staff_category)->toBe('W')
        ->and($employee->fresh()->work_pattern?->value)->toBe('security')
        ->and($employee->versions()->where('source', 'rule')->count())->toBe(1);
});

test('collar aliases are case insensitive and invalid values stop the import', function (): void {
    $mapping = app(ColumnMapping::class)->suggest(hcHeaders());
    $file = hcFile([
        hcRow(12345678, 'Fake Blue', '', 39151, '', 'bLuE'),
        hcRow(87654321, 'Fake White', '', 39151, '', 'WHITE'),
    ]);
    $preview = app(EmployeeImport::class)->previewMapped($this->company, $file, 'partial', today()->toDateString(), $mapping);

    expect($preview->errors())->toBe(0)
        ->and($preview->rows[0]['values']['staff_category'])->toBe('B')
        ->and($preview->rows[1]['values']['staff_category'])->toBe('W');

    $invalid = hcFile([hcRow(12345678, 'Fake Unknown', '', 39151, '', 'X')]);
    expect(app(EmployeeImport::class)->previewMapped($this->company, $invalid, 'partial', today()->toDateString(), $mapping)->errors())->toBe(1);
});

test('full files send missing employees to exit review and reappearance clears the review without deactivation', function (): void {
    $missing = Employee::factory()->create(['branch_id' => $this->branch->id, 'employee_code' => '00000001']);
    $import = app(EmployeeImport::class);
    $first = hcFile([hcRow(12345678, 'Fake Person', '', 39151)]);
    $mapping = app(ColumnMapping::class)->suggest(hcHeaders());
    $preview = $import->previewMapped($this->company, $first, 'full', today()->toDateString(), $mapping);
    expect(array_column($preview->missing, 'code'))->toContain('00000001');
    $import->commitMapped($this->company, $first, $this->actor, 'full', today()->toDateString(), $mapping, $preview->token);
    expect($missing->fresh()->exit_status)->toBe(EmployeeExitStatus::PendingReview)
        ->and($missing->fresh()->is_active)->toBeTrue();
    $partial = hcFile([hcRow('00000001', 'Returning Person', '', 39151)]);
    expect($import->previewMapped($this->company, $partial, 'partial', today()->toDateString(), $mapping)->missing)->toBe([]);
    expect($missing->fresh()->exit_status)->toBe(EmployeeExitStatus::PendingReview);
    $second = hcFile([hcRow('00000001', 'Returning Person', '', 39151), hcRow(12345678, 'Fake Person', '', 39151)]);
    $next = $import->previewMapped($this->company, $second, 'full', today()->toDateString(), $mapping);
    $import->commitMapped($this->company, $second, $this->actor, 'full', today()->toDateString(), $mapping, $next->token);
    expect($missing->fresh()->exit_status)->toBe(EmployeeExitStatus::None)
        ->and($missing->fresh()->is_active)->toBeTrue();
});

test('a changed preview cannot commit and the original download is tenant restricted', function (): void {
    $other = Company::factory()->create();
    $otherActor = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::HrStaff]);
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    $file = hcFile([hcRow(12345678, 'Fake Person', '', 39151)]);
    $mapping = app(ColumnMapping::class)->suggest(hcHeaders());
    $import = app(EmployeeImport::class);
    $preview = $import->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping);
    $this->branch->update(['name' => 'Renamed']);
    $result = $import->commitMapped($this->company, $file, $this->actor, 'full', today()->toDateString(), $mapping, $preview->token);
    expect($result['committed'])->toBeFalse()->and(EmployeeImportBatch::query()->count())->toBe(0);
    $this->branch->update(['name' => 'Hilla']);
    $fresh = $import->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping);
    $saved = $import->commitMapped($this->company, $file, $this->actor, 'full', today()->toDateString(), $mapping, $fresh->token);
    $url = route('employees.import.file', ['company' => $this->company->id, 'batch' => $saved['batch']->id]);
    $this->get($url)->assertOk();
    expect(AuditLog::query()->where('action', 'employee.import.file.downloaded')->count())->toBe(1);
    $this->actingAs($otherActor);
    $this->get($url)->assertNotFound();
    $this->actingAs($platform);
    $this->get($url)->assertOk();
});

test('the import page shows mapping before preview and commits the reviewed upload', function (): void {
    $file = hcFile([hcRow(12345678, 'Fake Person', '', 39151)]);
    Livewire::test(ImportEmployees::class)->set('sourceFile', $file)
        ->call('prepareMapping')->assertSet('step', 'mapping')
        ->call('previewMappedImport')->assertSet('step', 'preview')
        ->call('confirmMappedImport')->assertSet('step', 'upload');
    expect(Employee::query()->where('employee_code', '12345678')->exists())->toBeTrue();
});

test('the import page works with the database cache store used outside tests', function (): void {
    // The suite's array store keeps objects; the database store refuses to unserialize application classes.
    config(['cache.default' => 'database']);
    $file = hcFile([hcRow(12345679, 'Fake Person', '', 39151)]);
    Livewire::test(ImportEmployees::class)->set('sourceFile', $file)
        ->call('prepareMapping')->assertSet('step', 'mapping')
        ->call('previewMappedImport')->assertSet('step', 'preview')->assertSee('12345679')
        ->call('confirmMappedImport')->assertSet('step', 'upload');
    expect(Employee::query()->where('employee_code', '12345679')->exists())->toBeTrue();
});

test('mapped CSV keeps text codes with leading zeros and reports unchanged rows separately', function (): void {
    $file = UploadedFile::fake()->createWithContent('employees.csv', "employee_code,name,branch,hire_date\n00001234,Fake Person,Hilla,2024-01-15\n");
    $import = app(EmployeeImport::class);
    $mapping = app(ColumnMapping::class)->suggest(app(SpreadsheetReader::class)->read($file)['headers']);
    $preview = $import->previewMapped($this->company, $file, 'partial', today()->toDateString(), $mapping);
    expect($preview->rows[0]['code'])->toBe('00001234')->and($preview->added())->toBe(1);
    $import->commitMapped($this->company, $file, $this->actor, 'partial', today()->toDateString(), $mapping, $preview->token);

    $second = UploadedFile::fake()->createWithContent('repeat.csv', "employee_code,name,branch,hire_date\n00001234,Fake Person,Hilla,2024-01-15\n");
    $repeat = $import->previewMapped($this->company, $second, 'partial', today()->toDateString(), $mapping);
    expect($repeat->unchanged())->toBe(1)
        ->and(Employee::query()->where('employee_code', '00001234')->count())->toBe(1);
});

test('numeric identifiers too large for exact float representation are rejected', function (): void {
    expect(fn () => app(SpreadsheetReader::class)->value(9007199254740992.0, 'employee_code'))
        ->toThrow(ValidationException::class);
    expect(app(SpreadsheetReader::class)->value('0000123456789012', 'employee_code'))->toBe('0000123456789012');
    $file = hcFile([hcRow(123456789012345, 'Fake Person', '', 39151)]);
    $cell = app(SpreadsheetReader::class)->read($file)['rows'][0]['cells'][0];
    expect(app(SpreadsheetReader::class)->value($cell, 'employee_code'))->toBe('123456789012345');
});

test('unknown branches hold the row under the unmatched value and unmatched managers remain warnings', function (): void {
    $row = hcRow(12345678, 'Fake Person', '', 39151, '99999999');
    $row[23] = 'Unknown Site';
    $file = hcFile([$row]);
    $mapping = app(ColumnMapping::class)->suggest(hcHeaders());
    $preview = app(EmployeeImport::class)->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping);
    expect($preview->errors())->toBe(0)
        ->and($preview->held())->toBe(1)
        ->and($preview->unmatchedBranches())->toBe([['name' => 'Unknown Site', 'count' => 1]]);
    $valid = hcFile([hcRow(12345678, 'Fake Person', '', 39151, '99999999')]);
    $review = app(EmployeeImport::class)->previewMapped($this->company, $valid, 'full', today()->toDateString(), $mapping);
    expect($review->errors())->toBe(0)
        ->and($review->rows[0]['warnings'])->not->toBeEmpty();
});

test('a full file with held rows does not send anyone to exit review', function (): void {
    Employee::factory()->create(['branch_id' => $this->branch->id, 'employee_code' => '00000001']);
    $row = hcRow(12345678, 'Fake Person', '', 39151, '99999999');
    $row[23] = 'Unknown Site';
    $mapping = app(ColumnMapping::class)->suggest(hcHeaders());
    $preview = app(EmployeeImport::class)->previewMapped($this->company, hcFile([$row]), 'full', today()->toDateString(), $mapping);
    expect($preview->held())->toBe(1)
        ->and($preview->missing)->toBe([]);
});

test('a file whose rows were held can be imported again after the branch is linked', function (): void {
    $row = hcRow(12345678, 'Fake Person', '', 39151);
    $row[23] = 'Erbil Site';
    $file = hcFile([$row]);
    $mapping = app(ColumnMapping::class)->suggest(hcHeaders());
    $import = app(EmployeeImport::class);
    $held = $import->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping);
    $import->commitMapped($this->company, $file, $this->actor, 'full', today()->toDateString(), $mapping, $held->token);
    expect(Employee::query()->where('employee_code', '12345678')->exists())->toBeFalse();

    $this->branch->aliases()->create(['company_id' => $this->company->id, 'name' => 'Erbil Site', 'normalized_name' => BranchMatcher::normalise('Erbil Site')]);
    $again = $import->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping);
    expect($again->alreadyImported)->toBeNull()->and($again->added())->toBe(1);
    $result = $import->commitMapped($this->company, $file, $this->actor, 'full', today()->toDateString(), $mapping, $again->token);
    expect($result['committed'])->toBeTrue()
        ->and(Employee::query()->where('employee_code', '12345678')->value('branch_id'))->toBe($this->branch->id);
    expect($import->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping)->alreadyImported)->not->toBeNull();
});

test('a new employee counts from the hire date and later imports from their effective date', function (): void {
    $mapping = app(ColumnMapping::class)->suggest(hcHeaders());
    $import = app(EmployeeImport::class);
    $first = hcFile([hcRow(12345679, 'Fake Manager', '', 39151), hcRow(12345678, 'Fake Person', '', 39151, '12345679', '', 'Al Waha Company')]);
    $preview = $import->previewMapped($this->company, $first, 'full', today()->toDateString(), $mapping);
    $import->commitMapped($this->company, $first, $this->actor, 'full', today()->toDateString(), $mapping, $preview->token);
    $employee = Employee::query()->where('employee_code', '12345678')->firstOrFail();
    $manager = Employee::query()->where('employee_code', '12345679')->firstOrFail();
    $history = app(EmployeeHistory::class);

    expect($history->asOf($employee, '2020-01-01')['legal_entity'])->toBe('Al Waha Company')
        ->and($history->asOf($employee, '2020-01-01')['manager_employee_id'])->toBe($manager->id)
        ->and($history->asOf($employee, '2007-03-09')['legal_entity'])->toBeNull();

    $second = hcFile([hcRow(12345679, 'Fake Manager', '', 39151), hcRow(12345678, 'Fake Person', '', 39151, '12345679', '', 'Sardkar Company')]);
    $repeat = $import->previewMapped($this->company, $second, 'full', today()->toDateString(), $mapping);
    $import->commitMapped($this->company, $second, $this->actor, 'full', today()->toDateString(), $mapping, $repeat->token);

    expect($history->asOf($employee->fresh(), today()->subDay())['legal_entity'])->toBe('Al Waha Company')
        ->and($history->asOf($employee->fresh(), today())['legal_entity'])->toBe('Sardkar Company');
});
