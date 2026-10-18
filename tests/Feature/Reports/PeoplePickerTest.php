<?php

use App\Livewire\Cases\CreateCase;
use App\Livewire\Reports\SubmitReport;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Employees\EmployeePicker;
use App\Services\Reports\ReportService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function pickerFixture(): array
{
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $otherBranch = Branch::factory()->create(['company_id' => $company->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id,
        'name' => 'أحمد سالم', 'name_en' => 'Ahmed Salim', 'employee_code' => 'EMP-0551']);

    return compact('company', 'branch', 'otherBranch', 'reporter', 'employee');
}

test('picker searches Arabic English and number within the reporters branch and tenant', function (): void {
    $f = pickerFixture();
    Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['otherBranch']->id,
        'name' => 'أحمد آخر', 'name_en' => 'Ahmed Other']);
    $foreign = Company::factory()->create();
    app(TenantContext::class)->runForCompany($foreign, function (): void {
        Employee::factory()->create(['name' => 'أحمد خارجي', 'name_en' => 'Ahmed Foreign']);
    });
    $picker = app(EmployeePicker::class);
    foreach (['أحمد', 'احمد', 'Ahmed', '0551'] as $term) {
        expect(collect($picker->search($f['reporter'], $f['branch']->id, $term))->pluck('id')->all())
            ->toBe([$f['employee']->id]);
    }
    expect(fn () => $picker->employee($f['reporter'], $f['branch']->id,
        Employee::query()->where('branch_id', $f['otherBranch']->id)->firstOrFail()->id))->toThrow(ModelNotFoundException::class);
});

test('report shows a complete review and saves only after confirmation', function (): void {
    $f = pickerFixture();
    $this->actingAs($f['reporter']);
    $form = Livewire::test(SubmitReport::class)
        ->assertDontSee(__('Alleged responsible person'))
        ->set('branchId', (string) $f['branch']->id)
        ->set('peopleSearch.employees', 'Ahmed')->call('addPerson', 'employees', $f['employee']->id)
        ->set('peopleSearch.employees', 'Visitor Name')->call('addFreePerson', 'employees')
        ->set('peopleSearch.witnesses', 'Ahmed')->call('addPerson', 'witnesses', $f['employee']->id)
        ->set('place', 'Gate 3')->set('occurredAt', now()->subHour()->format('Y-m-d\TH:i'))
        ->set('violationType', 'Incident')->set('description', 'A detailed incident description')
        ->call('submit')->assertHasNoErrors()->assertSet('reviewing', true)
        ->assertSee('Visitor Name')->assertSee('أحمد سالم')->assertSee($f['branch']->name)->assertSee('Gate 3')
        ->assertSee('Incident')->assertSee('A detailed incident description');
    expect(ViolationReport::query()->count())->toBe(0);
    $form->call('editReview')->assertSet('reviewing', false)->assertSet('place', 'Gate 3')
        ->call('submit')->call('confirmSend')->assertHasNoErrors()->assertRedirect('/reports');
    $report = ViolationReport::query()->firstOrFail();
    expect($report->free_employee_names)->toBe(['Visitor Name'])
        ->and($report->alleged_responsible_employee_id)->toBeNull()
        ->and($report->witnesses()->firstOrFail()->employee_id)->toBe($f['employee']->id);
});

test('people tags can be removed and forged out-of-branch selections are rejected', function (): void {
    $f = pickerFixture();
    $outsider = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['otherBranch']->id]);
    $this->actingAs($f['reporter']);
    Livewire::test(SubmitReport::class)->set('branchId', (string) $f['branch']->id)
        ->call('addPerson', 'employees', $f['employee']->id)
        ->assertSet('people.employees', [['employee_id' => $f['employee']->id, 'name' => $f['employee']->name]])
        ->call('removePerson', 'employees', 0)->assertSet('people.employees', []);
    expect(fn () => app(EmployeePicker::class)->selections($f['reporter'], $f['branch']->id,
        [['employee_id' => $outsider->id, 'name' => 'Forged']], true))
        ->toThrow(ModelNotFoundException::class);
});

test('confirmation revalidates changes and cannot bypass review', function (): void {
    $f = pickerFixture();
    $this->actingAs($f['reporter']);
    $form = Livewire::test(SubmitReport::class)
        ->set('branchId', (string) $f['branch']->id)->set('place', 'Gate 3')
        ->set('occurredAt', now()->subHour()->format('Y-m-d\TH:i'))
        ->set('violationType', 'Incident')->set('description', 'Description');
    $form->call('submit')->assertSet('reviewing', true)
        ->set('description', '')->call('confirmSend')->assertHasErrors(['description']);
    expect(ViolationReport::query()->count())->toBe(0);
});

test('free employee names require real records before report conversion', function (): void {
    $f = pickerFixture();
    $hr = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'company_admin']);
    $report = ViolationReport::factory()->create(['company_id' => $f['company']->id,
        'branch_id' => $f['branch']->id, 'reporter_user_id' => $f['reporter']->id,
        'free_employee_names' => ['Visitor Name'], 'place_freetext' => 'Gate']);
    expect(fn () => app(ReportService::class)->convert($report, $hr))->toThrow(ValidationException::class);
    expect(DisciplinaryCase::query()->count())->toBe(0);
    $employee = Employee::factory()->create(['company_id' => $f['company']->id, 'branch_id' => $f['branch']->id,
        'name' => 'Visitor Name']);
    $case = app(ReportService::class)->convert($report, $hr, null, [
        'employee_id' => $employee->id, 'employee_ids' => [$employee->id],
    ]);
    expect($case->employee_id)->toBe($employee->id);
});

test('HR form uses the picker for employee witnesses and responsible person', function (): void {
    $f = pickerFixture();
    $hr = User::factory()->create(['company_id' => $f['company']->id, 'primary_role' => 'company_admin']);
    $this->actingAs($hr);
    Livewire::test(CreateCase::class)
        ->set('branchId', (string) $f['branch']->id)
        ->call('addPerson', 'employees', $f['employee']->id)
        ->call('addPerson', 'witnesses', $f['employee']->id)
        ->call('addPerson', 'responsible', $f['employee']->id)
        ->set('place', 'Gate')->set('violationTime', now()->subHour()->format('Y-m-d\TH:i'))
        ->set('violationType', 'Incident')->set('description', 'Description')
        ->call('save')->assertHasNoErrors();
    $case = DisciplinaryCase::query()->firstOrFail();
    expect($case->employee_id)->toBe($f['employee']->id)
        ->and($case->alleged_responsible_employee_id)->toBe($f['employee']->id)
        ->and($case->witnesses()->firstOrFail()->employee_id)->toBe($f['employee']->id);
});
