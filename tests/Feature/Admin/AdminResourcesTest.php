<?php

use App\Enums\UserRole;
use App\Filament\Admin\Pages\CompanySettings;
use App\Filament\Admin\Resources\BranchResource;
use App\Filament\Admin\Resources\BranchResource\Pages\ListBranchs;
use App\Filament\Admin\Resources\CommitteeMandateResource;
use App\Filament\Admin\Resources\CommitteeMandateResource\Pages\CreateMandate;
use App\Filament\Admin\Resources\CommitteeMandateResource\Pages\ListMandates;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Admin\Resources\LocationResource;
use App\Filament\Admin\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Admin\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Admin\Resources\UserResource;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Filament\Admin\Resources\UserResource\Pages\EditUser;
use App\Filament\Admin\Resources\UserResource\Pages\ListUsers;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\Company;
use App\Models\Employee;
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
    $this->admin = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::CompanyAdmin]);
    actingInAdminTenant($this, $this->admin, $this->company);
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('company admin can reach each setup resource through Livewire and direct URLs', function (): void {
    foreach ([
        BranchResource::class => ListBranchs::class,
        LocationResource::class => ListLocations::class,
        EmployeeResource::class => ListEmployees::class,
        UserResource::class => ListUsers::class,
        CommitteeMandateResource::class => ListMandates::class,
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
        $this->otherMandate = CommitteeMandate::factory()->create();
    });
    $this->otherUser = User::factory()->create(['company_id' => $this->otherCompany->id, 'primary_role' => UserRole::Reporter]);

    foreach ([$this->admin, User::factory()->create()] as $actor) {
        $this->actingAs($actor);
        foreach ([
            [BranchResource::class, 'edit', $this->otherBranch],
            [EmployeeResource::class, 'edit', $this->otherEmployee],
            [LocationResource::class, 'edit', $this->otherLocation],
            [CommitteeMandateResource::class, 'view', $this->otherMandate],
            [UserResource::class, 'edit', $this->otherUser],
        ] as [$resource, $page, $record]) {
            $url = $resource::getUrl($page, ['record' => $record->id], panel: 'admin', tenant: $this->company);
            expect($this->get($url)->status())->toBeIn([403, 404]);
        }
    }
});

test('HR staff have view-only branch and mandate access and only reporter or manager user options', function (): void {
    $hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $hr, $this->company);
    foreach ([ListBranchs::class, ListMandates::class, CreateLocation::class, CreateEmployee::class, CreateUser::class] as $component) {
        actingInAdminTenant($this, $hr, $this->company);
        Livewire::test($component)->assertOk();
    }
    actingInAdminTenant($this, $hr, $this->company);
    $branch = Branch::factory()->create();
    $this->get(BranchResource::getUrl('view', ['record' => $branch->id], panel: 'admin', tenant: $this->company))->assertOk();
    expect($this->get(BranchResource::getUrl('create', panel: 'admin', tenant: $this->company))->status())->toBe(403);
    expect($this->get(CommitteeMandateResource::getUrl('create', panel: 'admin', tenant: $this->company))->status())->toBe(403);
    expect(array_keys(UserResource::allowedRoles()))->toEqualCanonicalizing([
        UserRole::Reporter->value, UserRole::DeptManager->value,
    ]);
    actingInAdminTenant($this, $hr, $this->company);
    $roles = Livewire::test(CreateUser::class)->instance()->form->getFlatComponents()['primary_role']->getOptions();
    expect($roles)->not->toHaveKey(UserRole::CompanyAdmin->value);
    $url = UserResource::getUrl('edit', ['record' => $this->admin->id], panel: 'admin', tenant: $this->company);
    expect($this->get($url)->status())->toBeIn([403, 404]);
});

test('employee form generates a number and surfaces the single general manager rule', function (): void {
    $branch = Branch::factory()->create();
    actingInAdminTenant($this, $this->admin, $this->company);
    $firstForm = Livewire::test(CreateEmployee::class);
    actingInAdminTenant($this, $this->admin, $this->company);
    $firstForm->fillForm([
        'name' => 'المدير الأول',
        'gender' => 'male',
        'branch_id' => $branch->id,
        'department' => 'الإدارة',
        'position' => 'مدير عام',
        'hire_date' => '2026-01-01',
        'is_general_manager' => true,
    ]);
    actingInAdminTenant($this, $this->admin, $this->company);
    $firstForm->call('create')->assertHasNoFormErrors();
    actingInAdminTenant($this, $this->admin, $this->company);
    $first = Employee::query()->where('name', 'المدير الأول')->firstOrFail();
    expect($first->employee_code)->toStartWith('EMP-');

    actingInAdminTenant($this, $this->admin, $this->company);
    $secondForm = Livewire::test(CreateEmployee::class);
    actingInAdminTenant($this, $this->admin, $this->company);
    $secondForm->fillForm([
        'name' => 'المدير الثاني',
        'gender' => 'male',
        'branch_id' => $branch->id,
        'department' => 'الإدارة',
        'position' => 'مدير عام',
        'hire_date' => '2026-01-02',
        'is_general_manager' => true,
    ]);
    actingInAdminTenant($this, $this->admin, $this->company);
    $secondForm->call('create')->assertHasFormErrors(['is_general_manager']);
});

test('reporter creation saves branch scope in the same request', function (): void {
    $branch = Branch::factory()->create();
    actingInAdminTenant($this, $this->admin, $this->company);
    $form = Livewire::test(CreateUser::class);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form->fillForm([
        'name' => 'مبلّغ الفرع',
        'email' => 'branch-reporter@example.test',
        'password' => 'secret12345',
        'primary_role' => UserRole::Reporter->value,
        'branch_ids' => [$branch->id],
    ]);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form->call('create')->assertHasNoFormErrors();
    $user = User::query()->where('email', 'branch-reporter@example.test')->firstOrFail();
    $this->assertDatabaseHas('branch_user', [
        'company_id' => $this->company->id,
        'user_id' => $user->id,
        'branch_id' => $branch->id,
    ]);
});

test('mandate page rejects an equal effective date', function (): void {
    CommitteeMandate::factory()->create(['effective_from' => '2026-10-10']);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form = Livewire::test(CreateMandate::class);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form->fillForm([
        'order_number' => '12',
        'order_date' => '2026-10-01',
        'effective_from' => '2026-10-10',
    ]);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form->call('create')->assertHasFormErrors(['effective_from']);
});

test('registering a later order keeps the earlier order until the new effective date', function (): void {
    $old = CommitteeMandate::factory()->create(['effective_from' => '2026-10-01']);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form = Livewire::test(CreateMandate::class);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form->fillForm([
        'order_number' => '13',
        'order_date' => '2026-10-02',
        'effective_from' => '2026-10-15',
    ]);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form->call('create')->assertHasNoFormErrors();
    actingInAdminTenant($this, $this->admin, $this->company);
    expect($old->fresh()->superseded_at)->not->toBeNull();
    expect(CommitteeMandate::query()->inEffectOn('2026-10-14')->whereKey($old->id)->exists())->toBeTrue();
    expect(CommitteeMandate::query()->inEffectOn('2026-10-15')->whereKey($old->id)->exists())->toBeFalse();
});

test('the last active company admin remains active', function (): void {
    actingInAdminTenant($this, $this->admin, $this->company);
    $list = Livewire::test(ListUsers::class);
    actingInAdminTenant($this, $this->admin, $this->company);
    $list->assertTableActionExists('toggleActive', record: $this->admin);
    try {
        app(UserAccountService::class)->update($this->admin, ['is_active' => false], $this->admin);
        $this->fail('The last admin guard did not run.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('primary_role');
        expect($exception->errors()['primary_role'][0])->toContain('last active company administrator');
    }
    expect($this->admin->fresh()->is_active)->toBeTrue();

    actingInAdminTenant($this, $this->admin, $this->company);
    $form = Livewire::test(EditUser::class, ['record' => $this->admin->id]);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form->fillForm(['primary_role' => UserRole::HrStaff->value]);
    actingInAdminTenant($this, $this->admin, $this->company);
    $form->call('save')->assertHasFormErrors(['primary_role']);
});

test('company settings keep required languages and reject HR staff', function (): void {
    actingInAdminTenant($this, $this->admin, $this->company);
    Livewire::test(CompanySettings::class)->fillForm([
        'letterhead_address' => 'بغداد',
        'kurdish_enabled' => true,
        'requires_second_review' => true,
    ])->call('save')->assertHasNoFormErrors();
    expect($this->company->fresh()->enabled_languages)->toBe(['ar', 'en', 'ku']);
    expect($this->company->fresh()->requires_second_review)->toBeTrue();

    $hr = User::factory()->create(['company_id' => $this->company->id, 'primary_role' => UserRole::HrStaff]);
    $this->actingAs($hr);
    $url = CompanySettings::getUrl(panel: 'admin', tenant: $this->company);
    expect($this->get($url)->status())->toBeIn([403, 404]);
});
