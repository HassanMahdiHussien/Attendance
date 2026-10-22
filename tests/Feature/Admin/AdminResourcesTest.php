<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\CompanySettings;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranchs;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Admin\Resources\EmployeeResource\Pages\EditEmployee;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Admin\Resources\LocationResource;
use App\Filament\Admin\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Models\Location;
use App\Models\User;
use App\Services\UserAccountService;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->company = Company::factory()->create();
    $this->otherCompany = Company::factory()->create();
    $this->admin = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $this->admin, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('HR user can reach each setup resource through Livewire and direct URLs', function (): void {
    foreach ([
        BranchResource::class => ListBranchs::class,
        LocationResource::class => ListLocations::class,
        EmployeeResource::class => ListEmployees::class,
        UserResource::class => ListUsers::class,
    ] as $resource => $component) {
        actingInAdminTenant($this, $this->admin, $this->company);
        Livewire::test($component)->assertOk();
        $this->get($resource::getUrl('index', panel: 'admin', tenant: $this->company))->assertOk();
        $this->get($resource::getUrl('create', panel: 'admin', tenant: $this->company))->assertOk();
        actingInAdminTenant($this, $this->admin, $this->company);
    }
});

test('another company record id is refused on every direct edit or view URL', function (): void {
    app(TenantContext::class)->runForCompany($this->otherCompany, function (): void {
        $this->otherBranch = Branch::factory()->create();
        $this->otherEmployee = Employee::factory()->create(['branch_id' => $this->otherBranch->id]);
        $this->otherLocation = Location::factory()->create(['branch_id' => $this->otherBranch->id]);
    });
    $this->otherUser = User::factory()->create(['company_id' => $this->otherCompany->id, 'primary_role' => UserRole::HrStaff]);

    foreach ([$this->admin, User::factory()->create()] as $actor) {
        $this->actingAs($actor);
        foreach ([
            [BranchResource::class, 'edit', $this->otherBranch],
            [EmployeeResource::class, 'edit', $this->otherEmployee],
            [LocationResource::class, 'edit', $this->otherLocation],
            [UserResource::class, 'edit', $this->otherUser],
        ] as [$resource, $page, $record]) {
            $url = $resource::getUrl($page, ['record' => $record->id], panel: 'admin', tenant: $this->company);
            expect($this->get($url)->status())->toBeIn([403, 404]);
        }
    }
});

test('employee form generates a text employee code', function (): void {
    $branch = Branch::factory()->create();
    actingInAdminTenant($this, $this->admin, $this->company);
    Livewire::test(CreateEmployee::class)->fillForm([
        'name' => 'Example Employee',
        'gender' => 'male',
        'branch_id' => $branch->id,
        'department' => 'Administration',
        'position' => 'Manager',
        'hire_date' => '2026-01-01',
    ])->call('create')->assertHasNoFormErrors();

    $employee = Employee::query()->where('name', 'Example Employee')->firstOrFail();
    expect($employee->employee_code)->toStartWith('EMP-')
        ->and(EmployeeVersion::query()->where('employee_id', $employee->id)->exists())->toBeTrue();
});

test('employee edit records a dated version without changing unrelated fields', function (): void {
    $employee = Employee::factory()->create(['department' => 'Operations']);
    actingInAdminTenant($this, $this->admin, $this->company);
    Livewire::test(EditEmployee::class, ['record' => $employee->id])->fillForm([
        'work_pattern' => 'office',
        'schedule_end' => '17:00',
        'effective_from' => today()->toDateString(),
    ])->call('save')->assertHasNoFormErrors();

    expect($employee->fresh()->department)->toBe('Operations')
        ->and($employee->fresh()->work_pattern->value)->toBe('office')
        ->and(EmployeeVersion::query()->where('employee_id', $employee->id)->where('source', 'manual')->exists())->toBeTrue();
});

test('HR user creation is company scoped', function (): void {
    actingInAdminTenant($this, $this->admin, $this->company);
    Livewire::test(CreateUser::class)->fillForm([
        'name' => 'Second HR user',
        'email' => 'second-hr@example.test',
        'password' => 'secret12345',
        'primary_role' => UserRole::HrStaff->value,
    ])->call('create')->assertHasNoFormErrors();
    $user = User::query()->where('email', 'second-hr@example.test')->firstOrFail();
    expect($user->company_id)->toBe($this->company->id);
});

test('the last active HR user remains active', function (): void {
    actingInAdminTenant($this, $this->admin, $this->company);
    Livewire::test(ListUsers::class)->assertTableActionExists('toggleActive', record: $this->admin);
    try {
        app(UserAccountService::class)->update($this->admin, ['is_active' => false], $this->admin);
        $this->fail('The last HR guard did not run.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('primary_role');
        expect($exception->errors()['primary_role'][0])->toContain('last active HR user');
    }
    expect($this->admin->fresh()->is_active)->toBeTrue();

    $second = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    app(UserAccountService::class)->update($this->admin, ['is_active' => false], $second);
    expect($this->admin->fresh()->is_active)->toBeFalse();
});

test('company settings keep required languages and reject another company', function (): void {
    actingInAdminTenant($this, $this->admin, $this->company);
    Livewire::test(CompanySettings::class)->fillForm(['kurdish_enabled' => true])
        ->call('save')->assertHasNoFormErrors();
    expect($this->company->fresh()->enabled_languages)->toBe(['ar', 'en', 'ku']);

    $hr = User::factory()->create(['company_id' => $this->otherCompany->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($hr);
    $url = CompanySettings::getUrl(panel: 'admin', tenant: $this->company);
    expect($this->get($url)->status())->toBeIn([403, 404]);
});
