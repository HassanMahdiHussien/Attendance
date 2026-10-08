<?php

use App\Enums\UserRole;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeImport;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function employeeCsv(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('employees.csv', $content);
}

function employeeHeaders(): string
{
    return "name,employee_id,department,position,hire_date,branch,email,gender\r\n";
}

beforeEach(function (): void {
    app()->setLocale('ar');
    $this->company = Company::factory()->create();
    $this->admin = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::CompanyAdmin]);
    actingInAdminTenant($this, $this->admin, $this->company);
    $this->branch = Branch::factory()->create(['name' => 'بغداد']);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('UTF-8, BOM and Windows-1256 semicolon files preview Arabic names', function (): void {
    $row = "أحمد,EMP-25,الإدارة,مدير,2024-01-15,بغداد,,ذكر\r\n";
    $utf8 = employeeHeaders().$row;
    $arabic = "الاسم;رقم الموظف;القسم;المنصب;تاريخ التعيين;الفرع;البريد;الجنس\r\n"
        ."أحمد;EMP-25;الإدارة;مدير;15/01/2024;بغداد;;ذكر\r\n";
    foreach ([$utf8, "\xEF\xBB\xBF".$utf8, iconv('UTF-8', 'CP1256', $arabic)] as $content) {
        $preview = app(EmployeeImport::class)->preview($this->company, employeeCsv($content));
        expect($preview->errors())->toBe(0)
            ->and($preview->rows[0]['values']['name'])->toBe('أحمد')
            ->and($preview->rows[0]['values']['hire_date'])->toBe('2024-01-15');
    }
});

test('quoted commas and line breaks stay in one employee field', function (): void {
    $csv = employeeHeaders()."\"أحمد, علي\nحسن\",,الإدارة,موظف,2024-01-15,بغداد,,\r\n";
    $preview = app(EmployeeImport::class)->preview($this->company, employeeCsv($csv));
    expect($preview->errors())->toBe(0)
        ->and($preview->rows)->toHaveCount(1)
        ->and($preview->rows[0]['values']['name'])->toBe("أحمد, علي\nحسن");
});

test('duplicate aliases and malformed quotes are file errors', function (): void {
    foreach (["name,الاسم,department,position,hire_date,branch\n", employeeHeaders()."\"bad,,Dept,Job,2024-01-15,بغداد,,\n"] as $csv) {
        try {
            app(EmployeeImport::class)->preview($this->company, employeeCsv($csv));
            throw new RuntimeException('Expected a file error');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('sourceFile');
        }
    }
});

test('all invalid rows and duplicate numbers appear and nothing is saved', function (): void {
    $csv = employeeHeaders()
        ."أول,ABC,قسم,منصب,2024-01-15,مجهول,,\n"
        ."ثان,abc,قسم,منصب,31/02/2024,بغداد,,\n";
    $file = employeeCsv($csv);
    $import = app(EmployeeImport::class);
    $preview = $import->preview($this->company, $file);
    expect($preview->errors())->toBe(2)
        ->and($preview->rows[0]['errors'])->toContain('رقم مكرر في الملف', 'الفرع غير معروف')
        ->and($preview->rows[1]['errors'])->toContain('رقم مكرر في الملف', 'تاريخ التعيين غير صالح');
    $result = $import->commit($this->company, $file, $this->admin, $preview->hash);
    expect($result['committed'])->toBeFalse()
        ->and(Employee::query()->count())->toBe(0);
});

test('required fields, future dates, email, gender and ambiguous branches are row errors', function (): void {
    Branch::factory()->create(['name' => '  بغداد  ']);
    $csv = employeeHeaders().",,قسم,منصب,2099-01-01,بغداد,bad,unknown\n";
    $preview = app(EmployeeImport::class)->preview($this->company, employeeCsv($csv));
    expect($preview->rows[0]['errors'])->toContain('الاسم مطلوب', 'تاريخ التعيين غير صالح',
        'اسم الفرع غير مميز', 'البريد الإلكتروني غير صالح', 'الجنس غير صالح');
});

test('updates keep empty optional cells and inactive status, with field names in summary audit', function (): void {
    $employee = Employee::factory()->create([
        'branch_id' => $this->branch->id, 'employee_code' => 'ABC', 'name' => 'قديم',
        'email' => 'kept@example.com', 'gender' => 'female', 'is_active' => false,
        'is_general_manager' => true,
    ]);
    $file = employeeCsv(employeeHeaders()."جديد,abc,قسم,منصب,2024-01-15,بغداد,,\n");
    $import = app(EmployeeImport::class);
    $preview = $import->preview($this->company, $file);
    expect($preview->rows[0]['status'])->toBe('update-inactive')
        ->and($preview->rows[0]['diff'])->toContain('name')
        ->not->toContain('email', 'gender');
    $result = $import->commit($this->company, $file, $this->admin, $preview->hash);
    expect($result['committed'])->toBeTrue()
        ->and($employee->fresh()->email)->toBe('kept@example.com')
        ->and($employee->fresh()->gender->value)->toBe('female')
        ->and($employee->fresh()->is_active)->toBeFalse()
        ->and($employee->fresh()->is_general_manager)->toBeTrue();
    $audit = AuditLog::query()->where('action', 'employees.imported')->latest('id')->firstOrFail();
    expect($audit->metadata['sha256'])->toBe($preview->hash)
        ->and($audit->metadata['changed'][0]['fields'])->toContain('name')
        ->and($audit->metadata)->not->toHaveKey('file_name');
});

test('branch moves show the linked user warning without changing user permissions', function (): void {
    $otherBranch = Branch::factory()->create(['name' => 'البصرة']);
    $employee = Employee::factory()->create(['branch_id' => $this->branch->id, 'employee_code' => 'MOVE']);
    $user = User::factory()->create([
        'company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff, 'employee_id' => $employee->id,
    ]);
    $file = employeeCsv(employeeHeaders()."موظف,MOVE,قسم,منصب,2024-01-15,البصرة,,\n");
    $import = app(EmployeeImport::class);
    $preview = $import->preview($this->company, $file);
    expect($preview->rows[0]['diff'])->toContain('branch_id')
        ->and($preview->rows[0]['warnings'])->toContain('صلاحيات المستخدم المرتبط لا تتغير');
    $import->commit($this->company, $file, $this->admin, $preview->hash);
    expect($employee->fresh()->branch_id)->toBe($otherBranch->id)
        ->and($user->fresh()->primary_role)->toBe(UserRole::HrStaff);
});

test('generated codes skip manually used codes and confirmation uses the current sequence', function (): void {
    $file = employeeCsv(employeeHeaders()."أول,,قسم,منصب,2024-01-15,بغداد,,\nثان,,قسم,منصب,2024-01-15,بغداد,,\n");
    $import = app(EmployeeImport::class);
    $preview = $import->preview($this->company, $file);
    Employee::factory()->create(['branch_id' => $this->branch->id, 'employee_code' => 'EMP-0001']);
    Employee::factory()->create(['branch_id' => $this->branch->id, 'employee_code' => 'EMP-0003']);
    $result = $import->commit($this->company, $file, $this->admin, $preview->hash);
    expect($result['committed'])->toBeTrue()
        ->and(Employee::query()->whereIn('name', ['أول', 'ثان'])->orderBy('id')->pluck('employee_code')->all())
        ->toBe(['EMP-0002', 'EMP-0004']);
});

test('formula-looking cells remain ordinary stored text', function (): void {
    $file = employeeCsv(employeeHeaders()."=اسم,+ABC,@قسم,-منصب,2024-01-15,بغداد,,\n");
    $import = app(EmployeeImport::class);
    $preview = $import->preview($this->company, $file);
    expect($preview->errors())->toBe(0);
    $import->commit($this->company, $file, $this->admin, $preview->hash);
    $employee = Employee::query()->where('employee_code', '+ABC')->firstOrFail();
    expect($employee->name)->toBe('=اسم')
        ->and($employee->department)->toBe('@قسم')
        ->and($employee->position)->toBe('-منصب');
});

test('5,000 rows are accepted and 5,001 are rejected', function (): void {
    $line = "موظف,,قسم,منصب,2024-01-15,بغداد,,\n";
    expect(app(EmployeeImport::class)->preview($this->company, employeeCsv(employeeHeaders().str_repeat($line, 5000)))->rows)->toHaveCount(5000);
    expect(fn () => app(EmployeeImport::class)->preview($this->company, employeeCsv(employeeHeaders().str_repeat($line, 5001))))
        ->toThrow(ValidationException::class);
});

test('empty files, missing headers and files over 2 MB are rejected before preview', function (): void {
    foreach (['', "name,branch\n", employeeHeaders().str_repeat('x', 2 * 1024 * 1024)] as $csv) {
        expect(fn () => app(EmployeeImport::class)->preview($this->company, employeeCsv($csv)))
            ->toThrow(ValidationException::class);
    }
});

test('import page and template are restricted to the selected company and role', function (): void {
    $url = EmployeeResource::getUrl('import', panel: 'admin', tenant: $this->company);
    Livewire::test(ImportEmployees::class)->assertOk();
    $this->get($url)->assertOk();
    $template = $this->get(route('employees.import.template', ['company' => $this->company->id]))->assertOk();
    expect($template->getContent())->toStartWith("\xEF\xBB\xBFالاسم,رقم الموظف")
        ->and(substr_count($template->getContent(), "\n"))->toBe(1);
    $file = employeeCsv(employeeHeaders()."أول,,قسم,منصب,2024-01-15,بغداد,,\n");
    foreach ([UserRole::HrStaff, UserRole::Reporter] as $role) {
        $actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => $role]);
        actingInAdminTenant($this, $actor, $this->company);
        Livewire::test(ImportEmployees::class)->assertForbidden();
        expect(fn () => app(EmployeeImport::class)->preview($this->company, $file))->toThrow(AuthorizationException::class);
        expect(fn () => app(EmployeeImport::class)->commit($this->company, $file, $actor, hash_file('sha256', $file->getRealPath())))
            ->toThrow(AuthorizationException::class);
        $this->get($url)->assertForbidden();
        $this->get(route('employees.import.template', ['company' => $this->company->id]))->assertForbidden();
    }
    $other = Company::factory()->create();
    actingInAdminTenant($this, $this->admin, $other);
    Livewire::test(ImportEmployees::class)->assertForbidden();
    expect(fn () => app(EmployeeImport::class)->preview($other, $file))->toThrow(AuthorizationException::class);
    $this->get(route('employees.import.template', ['company' => $other->id]))->assertForbidden();
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => UserRole::PlatformAdmin]);
    actingInAdminTenant($this, $platform, $this->company);
    Livewire::test(ImportEmployees::class)->assertOk();
});

test('replaced upload cannot be confirmed', function (): void {
    $first = employeeCsv(employeeHeaders()."أول,,قسم,منصب,2024-01-15,بغداد,,\n");
    $replacement = employeeCsv(employeeHeaders()."ثان,,قسم,منصب,2024-01-15,بغداد,,\n");
    Livewire::test(ImportEmployees::class)->set('sourceFile', $first)->call('previewImport')
        ->set('sourceFile', $replacement)->call('confirmImport')->assertHasErrors(['sourceFile']);
    expect(Employee::query()->count())->toBe(0);
});

test('preview and confirm recheck the actor and tenant after the page mounted', function (): void {
    $file = employeeCsv(employeeHeaders()."أول,,قسم,منصب,2024-01-15,بغداد,,\n");
    $page = Livewire::test(ImportEmployees::class)->set('sourceFile', $file);
    $hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $hr, $this->company);
    $page->call('previewImport')->assertForbidden();

    actingInAdminTenant($this, $this->admin, $this->company);
    $page = Livewire::test(ImportEmployees::class)->set('sourceFile', $file)->call('previewImport');
    $other = Company::factory()->create();
    actingInAdminTenant($this, $this->admin, $other);
    $page->call('confirmImport')->assertForbidden();
    expect(Employee::withoutGlobalScopes()->where('name', 'أول')->count())->toBe(0);
});

test('a changed branch makes confirm fail and leaves the row error visible', function (): void {
    $file = employeeCsv(employeeHeaders()."أول,,قسم,منصب,2024-01-15,بغداد,,\n");
    $page = Livewire::test(ImportEmployees::class)->set('sourceFile', $file)->call('previewImport');
    $this->branch->delete();
    $page->call('confirmImport')->assertSee('الفرع غير معروف');
    expect(Employee::query()->count())->toBe(0);
});
