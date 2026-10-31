<?php

use App\Enums\UserRole;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ImportEmployees;
use App\Jobs\ImportEmployeesJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeImportBatch;
use App\Models\EmployeeImportRun;
use App\Models\User;
use App\Services\Employees\ColumnMapping;
use App\Services\Employees\EmployeeImport;
use App\Services\Employees\SpreadsheetReader;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Queue::fake();
    Storage::fake('local');
    $this->company = Company::factory()->create();
    app(TenantContext::class)->setCompany($this->company);
    Branch::factory()->create(['company_id' => $this->company->id, 'name' => 'Hilla']);
    $this->actor = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->actor, $this->company);
    $this->import = app(EmployeeImport::class);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

function queuedEmployeeFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('employees.csv', "employee_code,name,branch,hire_date\n00000001,Fake Person,Hilla,2024-01-15\n");
}

function queueEmployeeFile(EmployeeImport $import, Company $company, User $actor): EmployeeImportRun
{
    $file = queuedEmployeeFile();
    $mapping = app(ColumnMapping::class)->suggest(app(SpreadsheetReader::class)->read($file)['headers']);
    $preview = $import->previewMapped($company, $file, 'partial', today()->toDateString(), $mapping);

    return $import->queueMapped($company, $file, $actor, 'partial', today()->toDateString(), $mapping, $preview->token);
}

test('job links one batch and remains idempotent when handled twice', function (): void {
    $run = queueEmployeeFile($this->import, $this->company, $this->actor);
    Queue::assertPushed(ImportEmployeesJob::class);
    expect(Employee::query()->count())->toBe(0);
    $job = new ImportEmployeesJob($run->id);
    $job->handle($this->import);
    $job->handle($this->import);
    expect($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->employee_import_batch_id)->toBe(EmployeeImportBatch::query()->sole()->id)
        ->and(EmployeeImportBatch::query()->count())->toBe(1)
        ->and(Employee::query()->where('employee_code', '00000001')->count())->toBe(1);
});

test('changed stored file fails without writing employees', function (): void {
    $run = queueEmployeeFile($this->import, $this->company, $this->actor);
    Storage::disk('local')->put($run->file_path, 'changed');
    (new ImportEmployeesJob($run->id))->handle($this->import);
    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->error)->toBe('stored_file_changed')
        ->and(EmployeeImportBatch::query()->count())->toBe(0)
        ->and(Employee::query()->count())->toBe(0);
});

test('permission revoked before the job fails without writing employees', function (): void {
    $run = queueEmployeeFile($this->import, $this->company, $this->actor);
    $this->actor->forceFill(['is_active' => false])->save();
    (new ImportEmployeesJob($run->id))->handle($this->import);
    expect($run->fresh()->status)->toBe('failed')
        ->and($run->fresh()->error)->toBe('permission_revoked')
        ->and(EmployeeImportBatch::query()->count())->toBe(0)
        ->and(Employee::query()->count())->toBe(0);
});

test('run scope and import page polling never expose another company', function (): void {
    $run = queueEmployeeFile($this->import, $this->company, $this->actor);
    $other = Company::factory()->create();
    $otherActor = User::factory()->create(['company_id' => $other->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $otherActor, $other);
    expect(EmployeeImportRun::query()->find($run->id))->toBeNull();
    Livewire::test(ImportEmployees::class)->assertSet('runId', null)->call('pollImport')->assertDontSee('Importing 1 employees');
});
