<?php

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employees\EmployeeProfile;
use App\Support\Tenancy\TenantContext;

test('employee search and direct page obey company and role scope', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    app(TenantContext::class)->setCompany($a);
    $employee = Employee::factory()->create(['name' => 'Example Employee']);
    $admin = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'hr_staff']);
    app(TenantContext::class)->setCompany($b);
    $outsider = User::factory()->create(['company_id' => $b->id, 'primary_role' => 'hr_staff']);

    $this->actingAs($admin)->get('/employees')->assertOk()->assertSee($employee->name);
    $this->get('/employees/'.$employee->id)->assertOk()->assertSee($employee->name)
        ->assertSee('Attendance details will appear here.');
    $this->actingAs($outsider)->get('/employees/'.$employee->id)->assertNotFound();

    $platform = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
    $this->actingAs($platform)->get('/employees')->assertOk()->assertSee($employee->name);
    $this->get('/employees/'.$employee->id)->assertOk();
});

test('employee profile returns identity without case history', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $viewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $employee = Employee::factory()->create();

    $profile = app(EmployeeProfile::class)->build($viewer, $employee);

    expect($profile['employee']->is($employee))->toBeTrue()
        ->and($profile)->not->toHaveKey('history');
    $this->actingAs($viewer)->get(route('employees.show', $employee))->assertOk()
        ->assertSee('Attendance details will appear here.');
});
