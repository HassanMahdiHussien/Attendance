<?php

use App\Enums\UserRole;
use App\Filament\Admin\Resources\CommitteeMandateResource\Pages\ViewMandate;
use App\Filament\Admin\Resources\CommitteeMandateResource\RelationManagers\PoolMembersRelationManager;
use App\Filament\Admin\Resources\EmployeeResource;
use App\Filament\Admin\Resources\EmployeeResource\Pages\CreateEmployee;
use App\Filament\Admin\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Admin\Resources\UserResource\Pages\CreateUser;
use App\Models\Branch;
use App\Models\CommitteeMandate;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

class TenantSwitchProbe extends Dashboard
{
    public ?int $observedCompanyId = null;

    public ?int $employeeCount = null;

    public function checkContext(): void
    {
        $this->observedCompanyId = app(TenantContext::class)->companyId();
        $this->employeeCount = Employee::count();
    }
}

function tenantSwitchSnapshot(TestCase $test, Company $company): string
{
    $html = $test->get('/admin/'.$company->id.'/tenant-switch-probe')->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if (array_key_exists('observedCompanyId', json_decode($snapshot, true)['data'] ?? [])) {
            return $snapshot;
        }
    }

    throw new RuntimeException('The tenant switch probe snapshot was not rendered.');
}

function tenantSwitchUpdate(TestCase $test, string $snapshot): TestResponse
{
    return $test->postJson(Livewire::getUpdateUri(), [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'checkContext', 'params' => []]],
        ]],
    ], ['X-Livewire' => 'true']);
}

beforeEach(function (): void {
    $this->firstCompany = Company::factory()->create();
    $this->secondCompany = Company::factory()->create();
    $this->platformAdmin = User::factory()->create();
    actingInAdminTenant($this, $this->platformAdmin, $this->firstCompany);
    $panel = Filament::getPanel('admin');
    Route::middleware([...$panel->getMiddleware(), ...$panel->getAuthMiddleware(), ...$panel->getTenantMiddleware()])
        ->get('/admin/{tenant}/tenant-switch-probe', TenantSwitchProbe::class)
        ->name($panel->generateRouteName('pages.tenant-switch-probe'));
});

afterEach(function (): void {
    Filament::setTenant(null);
    app(TenantContext::class)->clear();
});

test('platform admin tenant switch changes Livewire table and direct URL scope', function (): void {
    $first = Employee::factory()->create();
    $second = app(TenantContext::class)->runForCompany($this->secondCompany, fn () => Employee::factory()->create());
    actingInAdminTenant($this, $this->platformAdmin, $this->firstCompany);
    $firstList = Livewire::test(ListEmployees::class);
    actingInAdminTenant($this, $this->platformAdmin, $this->firstCompany);
    $firstList->assertCanSeeTableRecords([$first])->assertCanNotSeeTableRecords([$second]);

    actingInAdminTenant($this, $this->platformAdmin, $this->secondCompany);
    $secondList = Livewire::test(ListEmployees::class);
    actingInAdminTenant($this, $this->platformAdmin, $this->secondCompany);
    $secondList->assertCanSeeTableRecords([$second])->assertCanNotSeeTableRecords([$first]);

    actingInAdminTenant($this, $this->platformAdmin, $this->secondCompany);
    $branch = Branch::factory()->create();
    actingInAdminTenant($this, $this->platformAdmin, $this->secondCompany);
    $form = Livewire::test(CreateEmployee::class);
    actingInAdminTenant($this, $this->platformAdmin, $this->secondCompany);
    $form->fillForm([
        'branch_id' => $branch->id,
        'name' => 'موظف الشركة الثانية',
        'department' => 'الموارد البشرية',
        'position' => 'موظف',
        'hire_date' => '2026-10-01',
    ]);
    actingInAdminTenant($this, $this->platformAdmin, $this->secondCompany);
    $form->call('create')->assertHasNoFormErrors();
    $this->assertDatabaseHas('employees', ['company_id' => $this->secondCompany->id, 'name' => 'موظف الشركة الثانية']);

    foreach ([[$this->firstCompany, 1], [$this->secondCompany, 2]] as [$company, $count]) {
        $snapshot = tenantSwitchSnapshot($this, $company);
        Filament::setTenant(null);
        app(TenantContext::class)->setAllCompanies();
        $response = tenantSwitchUpdate($this, $snapshot)->assertOk();
        $updated = json_decode($response->json('components.0.snapshot'), true);
        expect($updated['data']['observedCompanyId'])->toBe($company->id);
        expect($updated['data']['employeeCount'])->toBe($count);
    }

    $url = EmployeeResource::getUrl('edit', ['record' => $first->id], panel: 'admin', tenant: $this->secondCompany);
    expect($this->get($url)->status())->toBeIn([403, 404]);
});

test('relation manager stores pool members in the selected company', function (): void {
    $mandate = CommitteeMandate::factory()->create(['effective_from' => '2026-10-10']);
    $branch = Branch::factory()->create();
    $foreignUser = User::factory()->create(['company_id' => $this->secondCompany->id, 'primary_role' => UserRole::CommitteeMember]);
    $memberUser = User::factory()->create(['company_id' => $this->firstCompany->id, 'primary_role' => UserRole::CommitteeMember]);

    actingInAdminTenant($this, $this->platformAdmin, $this->firstCompany);
    $component = Livewire::test(PoolMembersRelationManager::class, [
        'ownerRecord' => $mandate,
        'pageClass' => ViewMandate::class,
    ]);
    actingInAdminTenant($this, $this->platformAdmin, $this->firstCompany);
    $options = $component->instance()->form->getFlatComponents()['user_id']->getOptions();
    expect($options)->toHaveKey($memberUser->id)->not->toHaveKey($foreignUser->id);

    actingInAdminTenant($this, $this->platformAdmin, $this->firstCompany);
    $component->callTableAction('create', null, [
        'role_type' => 'legal',
        'person_name' => 'عضو قانوني',
        'branch_id' => $branch->id,
        'user_id' => $memberUser->id,
    ]);
    $this->assertDatabaseHas('committee_pool_members', [
        'company_id' => $this->firstCompany->id,
        'mandate_id' => $mandate->id,
        'branch_id' => $branch->id,
        'user_id' => $memberUser->id,
    ]);

    $hr = User::factory()->create(['company_id' => $this->firstCompany->id, 'primary_role' => UserRole::HrStaff]);
    actingInAdminTenant($this, $hr, $this->firstCompany);
    $hrComponent = Livewire::test(PoolMembersRelationManager::class, [
        'ownerRecord' => $mandate,
        'pageClass' => ViewMandate::class,
    ]);
    actingInAdminTenant($this, $hr, $this->firstCompany);
    $hrComponent->assertTableActionHidden('create');
});

test('company user form employee and branch selectors stay within the tenant', function (): void {
    $branch = Branch::factory()->create();
    $employee = Employee::factory()->create(['branch_id' => $branch->id]);
    [$foreignBranch, $foreignEmployee] = app(TenantContext::class)->runForCompany($this->secondCompany, function (): array {
        $otherBranch = Branch::factory()->create();

        return [$otherBranch, Employee::factory()->create(['branch_id' => $otherBranch->id])];
    });

    actingInAdminTenant($this, $this->platformAdmin, $this->firstCompany);
    $component = Livewire::test(CreateUser::class);
    actingInAdminTenant($this, $this->platformAdmin, $this->firstCompany);
    $fields = $component->instance()->form->getFlatComponents(withHidden: true);
    expect($fields['employee_id']->getOptions())->toHaveKey($employee->id)->not->toHaveKey($foreignEmployee->id);
    expect($fields['branch_ids']->getOptions())->toHaveKey($branch->id)->not->toHaveKey($foreignBranch->id);
});
