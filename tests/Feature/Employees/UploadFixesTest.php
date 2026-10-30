<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Branches\BranchNames;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeImport;
use App\Services\Employees\EmployeeTemplate;
use App\Services\Employees\SpreadsheetReader;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
    $this->branch = Branch::factory()->create(['name' => 'أربيل']);
});

test('legacy and mapped employee imports use aliases, hold unknown branches once and import other rows', function (): void {
    app(BranchNames::class)->save($this->branch, ['Erbil']);
    $csv = "name\temployee_id\tdepartment\tposition\thire_date\tbranch\temail\tgender\n"
        ."Fake One\tEMP-1\tOperations\tAnalyst\t2024-01-01\tERBIL\t\tmale\n"
        ."Fake Two\tEMP-2\tOperations\tAnalyst\t2024-01-01\tSouth\t\tmale\n"
        ."Fake Three\tEMP-3\tOperations\tAnalyst\t2024-01-01\tSouth\t\tmale\n";
    $file = UploadedFile::fake()->createWithContent('employees.csv', $csv);
    $import = app(EmployeeImport::class);
    $legacy = $import->preview($this->company, $file);
    expect($legacy->added())->toBe(1)->and($legacy->held())->toBe(2)
        ->and($legacy->unmatchedBranches())->toBe([['name' => 'South', 'count' => 2]]);
    $sheet = app(SpreadsheetReader::class)->read($file);
    $mapping = app(ColumnMapping::class)->suggest($sheet['headers']);
    $mapped = $import->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping);
    expect($mapped->added())->toBe(1)->and($mapped->held())->toBe(2);
    $result = $import->commitMapped($this->company, $file, $this->actor, 'full', today()->toDateString(), $mapping, $mapped->token);
    expect($result['committed'])->toBeTrue()->and(Employee::query()->count())->toBe(1);

    app(BranchNames::class)->link($this->company, 'South', $this->branch->id);
    $rerun = $import->previewMapped($this->company, $file, 'full', today()->toDateString(), $mapping);
    expect($rerun->held())->toBe(0);
});

test('damaged tab separated headers explain how to save the file', function (): void {
    $file = UploadedFile::fake()->createWithContent('employees.csv', "?????\t??????\t????\nFake\t1\tErbil\n");
    expect(fn () => app(SpreadsheetReader::class)->read($file))->toThrow(ValidationException::class);
    try {
        app(EmployeeImport::class)->preview($this->company, $file);
    } catch (ValidationException $exception) {
        expect($exception->errors()['sourceFile'][0])->toBe(__('messages.import.damaged_arabic_header'));
    }
});

test('creating an unmatched branch requires jurisdiction and then reruns as a match', function (): void {
    $file = UploadedFile::fake()->createWithContent('employees.csv',
        "name,employee_id,department,position,hire_date,branch,email,gender\n"
        ."Fake Employee,EMP-7,Operations,Analyst,2024-01-01,Karbala,,male\n");
    $import = app(EmployeeImport::class);
    expect($import->preview($this->company, $file)->held())->toBe(1);
    expect(fn () => app(BranchNames::class)->create($this->company, 'Karbala', ''))->toThrow(ValidationException::class);
    $created = app(BranchNames::class)->create($this->company, 'Karbala', 'federal_iraq');
    $rerun = $import->preview($this->company, $file);
    expect($rerun->held())->toBe(0)->and($rerun->rows[0]['values']['branch_id'])->toBe($created->id);
});

test('another company branch alias cannot resolve this company file', function (): void {
    $other = Company::factory()->create();
    app(TenantContext::class)->setCompany($other);
    $foreign = Branch::factory()->create(['name' => 'الحلة']);
    $foreign->aliases()->create(['company_id' => $other->id, 'name' => 'Hilla', 'normalized_name' => 'hilla']);
    app(TenantContext::class)->setCompany($this->company);
    $file = UploadedFile::fake()->createWithContent('employees.csv',
        "name,employee_id,department,position,hire_date,branch,email,gender\n"
        ."Fake Employee,EMP-8,Operations,Analyst,2024-01-01,Hilla,,male\n");
    expect(app(EmployeeImport::class)->preview($this->company, $file)->held())->toBe(1);
});

test('Excel template has import headers and no employee rows', function (): void {
    $response = $this->get(route('employees.import.template', ['company' => $this->company->id]))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    try {
        $file = UploadedFile::fake()->createWithContent('employees-template.xlsx', file_get_contents($path));
        $sheet = app(SpreadsheetReader::class)->read($file);
        expect($sheet['headers'])->toBe(app(EmployeeTemplate::class)->headers())->and($sheet['rows'])->toBe([]);
        $reader = new Reader;
        $reader->open($path);
        try {
            $sheets = array_values(iterator_to_array($reader->getSheetIterator()));
            expect($sheets)->toHaveCount(2)
                ->and($sheets[1]->getName())->toBe('Help');
        } finally {
            $reader->close();
        }
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});
