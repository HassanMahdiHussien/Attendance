<?php

use App\Livewire\Reports\SubmitReport;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Livewire\Livewire;

// Found by the TesterArmy e2e run: a reporter got no confirmation after sending, and an unassigned reporter saw
// only an empty branch list.
test('a sent report confirms itself and an unassigned reporter is told to ask HR', function (): void {
    $notice = __('Your account is not assigned to any branch yet. Ask HR to add your branch before submitting a report.');
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $unassigned = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    $this->actingAs($unassigned);
    Livewire::test(SubmitReport::class)->assertSee($notice);

    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter']);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    $employee = Employee::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id]);
    $this->actingAs($reporter);
    Livewire::test(SubmitReport::class)
        ->assertDontSee($notice)
        ->set('branchId', (string) $branch->id)->set('place', 'Line 3')
        ->call('addPerson', 'employees', $employee->id)->set('witnessesNone', true)
        ->set('occurredAt', '2026-10-09T03:00')->set('violationType', 'Fight')
        ->set('description', 'A fight occurred')
        ->call('submit')->assertHasNoErrors()->assertSet('reviewing', true)
        ->call('confirmSend')->assertHasNoErrors()->assertRedirect('/reports');
    expect(session('status'))->toBe(__('Report sent. HR will review it.'));
});

test('report validation speaks Arabic with field names and refuses a future incident date', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $branch = Branch::factory()->create(['company_id' => $company->id]);
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'reporter', 'locale' => 'ar']);
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => $branch->id, 'user_id' => $reporter->id]);
    // A second branch, because a reporter with a single branch has it chosen automatically.
    BranchUser::factory()->create(['company_id' => $company->id, 'branch_id' => Branch::factory()->create(['company_id' => $company->id])->id, 'user_id' => $reporter->id]);
    $this->actingAs($reporter);
    app()->setLocale('ar');
    $component = Livewire::test(SubmitReport::class)->call('submit')->assertHasErrors(['branchId', 'description']);
    expect($component->errors()->first('branchId'))->toBe('حقل الفرع مطلوب.');

    Livewire::test(SubmitReport::class)
        ->set('branchId', (string) $branch->id)->set('place', 'Line 3')
        ->set('occurredAt', now()->addDay()->format('Y-m-d\TH:i'))->set('violationType', 'Fight')
        ->set('description', 'A fight occurred')
        ->call('submit')->assertHasErrors(['occurredAt']);
    app()->setLocale('en');
});
