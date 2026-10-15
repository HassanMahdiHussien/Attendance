<?php

use App\Livewire\Reports\SubmitReport;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Company;
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
    $this->actingAs($reporter);
    Livewire::test(SubmitReport::class)
        ->assertDontSee($notice)
        ->set('branchId', (string) $branch->id)->set('place', 'Line 3')
        ->set('occurredAt', '2026-10-09T03:00')->set('violationType', 'Fight')
        ->set('description', 'A fight occurred')
        ->call('submit')->assertHasNoErrors()->assertRedirect('/reports');
    expect(session('status'))->toBe(__('Report sent. HR will review it.'));
});
