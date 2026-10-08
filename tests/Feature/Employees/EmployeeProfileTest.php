<?php

use App\Enums\CaseStatus;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Models\HrPartner;
use App\Models\Law;
use App\Models\User;
use App\Services\Employees\EmployeeProfile;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

test('employee search and direct page obey company and role scope', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    app(TenantContext::class)->setCompany($a);
    $employee = Employee::factory()->create(['name' => 'موظف الاختبار']);
    $admin = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'company_admin']);
    $reporter = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'reporter']);
    app(TenantContext::class)->setCompany($b);
    $outsider = User::factory()->create(['company_id' => $b->id, 'primary_role' => 'company_admin']);

    $this->actingAs($admin)->get('/employees')->assertOk()->assertSee($employee->name);
    $this->get('/employees/'.$employee->id)->assertOk()->assertSee($employee->name);
    $this->actingAs($outsider)->get('/employees/'.$employee->id)->assertNotFound();
    $this->get('/employees')->assertDontSee($employee->name);
    $this->actingAs($reporter)->get('/employees')->assertForbidden();
    $this->get('/employees/'.$employee->id)->assertNotFound();
    $platform = User::factory()->create(['company_id' => null, 'primary_role' => 'platform_admin']);
    $this->actingAs($platform)->get('/employees')->assertOk()->assertSee($employee->name);
    $this->get('/employees/'.$employee->id)->assertOk();

    $partner = HrPartner::factory()->create();
    DB::table('company_hr_partner')->insert(['company_id' => $a->id, 'hr_partner_id' => $partner->id, 'full_case_access' => false]);
    $viewer = User::factory()->create(['company_id' => null, 'primary_role' => 'hr_partner_staff', 'hr_partner_id' => $partner->id]);
    $this->actingAs($viewer)->get('/employees/'.$employee->id)->assertNotFound();
    $this->get('/employees')->assertDontSee($employee->name);
    DB::table('company_hr_partner')->where('company_id', $a->id)->update(['full_case_access' => true]);
    $this->get('/employees/'.$employee->id)->assertOk()->assertSee($employee->name);
    $this->get('/employees')->assertSee($employee->name);
});

test('HR staff profile counts only visible cases and explains partial history', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $viewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $other = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'hr_staff']);
    $employee = Employee::factory()->create();
    $law = Law::factory()->create();
    DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'law_version_id' => $law->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $viewer->id]);
    DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'law_version_id' => $law->id,
        'status' => CaseStatus::Open, 'opened_by_user_id' => $other->id]);
    DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'law_version_id' => $law->id,
        'status' => CaseStatus::ArchivedFinal, 'outcome' => 'warning', 'closed_at' => now(),
        'opened_by_user_id' => $viewer->id]);
    $profile = app(EmployeeProfile::class)->build($viewer, $employee);
    expect($profile['counts']['total'])->toBe(2)->and($profile['counts']['warnings'])->toBe(1)
        ->and($profile['history'])->toHaveCount(1)->and($profile['partial'])->toBeTrue();
    $this->actingAs($viewer)->get('/employees/'.$employee->id)->assertOk()
        ->assertSee('قد توجد حالات أخرى لا تظهر لك');
});

test('history links only issued documents with a ready PDF', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $viewer = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $employee = Employee::factory()->create();
    $case = DisciplinaryCase::factory()->create(['employee_id' => $employee->id,
        'law_version_id' => Law::factory()->create()->id, 'status' => CaseStatus::ArchivedFinal,
        'closed_at' => now()]);
    $issued = Document::factory()->create(['case_id' => $case->id, 'status' => 'issued',
        'is_locked' => true, 'pdf_status' => 'ready', 'pdf_file_path' => 'documents/test.pdf']);
    $draft = Document::factory()->create(['case_id' => $case->id, 'document_type' => 'recommendations']);
    $this->actingAs($viewer)->get('/employees/'.$employee->id)->assertOk()
        ->assertSee(route('documents.pdf', $issued))->assertDontSee(route('documents.pdf', $draft));
});
