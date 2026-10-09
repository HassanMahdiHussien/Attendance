<?php

use App\Livewire\GlobalSearch as SearchComponent;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\CompanyLawFirmPartner;
use App\Models\DisciplinaryCase;
use App\Models\EmailDraft;
use App\Models\Employee;
use App\Models\HrPartner;
use App\Models\Law;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Cases\ConsultationService;
use App\Services\Search\GlobalSearch;
use App\Support\Search\SearchText;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

test('Arabic search normalization folds letters digits marks and whitespace', function (): void {
    expect(SearchText::normalize('  أَحْمَدـ  ٠٠٠٣  '))->toBe('احمد 0003')
        ->and(SearchText::normalize('إلهام آمنة ٱسم فاطمة على مسؤول شئون ABC ۱۲'))
        ->toBe('الهام امنه اسم فاطمه علي مسوول شيون abc 12');
});

test('stored search text follows model writes and seeded rows do not drift', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create(['name' => 'أحمد']);
    $report = ViolationReport::factory()->create(['violation_type' => 'تأخير', 'place_freetext' => 'الحلّة']);
    $draft = EmailDraft::factory()->create(['subject' => 'إشعارٌ من HR']);
    expect($employee->search_name)->toBe(SearchText::normalize($employee->name))
        ->and($report->search_text)->toBe(SearchText::normalize($report->violation_type.' '.$report->place_freetext))
        ->and($draft->search_subject)->toBe(SearchText::normalize($draft->subject));
    $employee->update(['name' => 'آمنة']);
    $report->update(['violation_type' => 'غياب']);
    $draft->update(['subject' => 'بلاغ ٠٣']);
    expect($employee->fresh()->search_name)->toBe('امنه')
        ->and($report->fresh()->search_text)->toBe(SearchText::normalize('غياب '.$report->place_freetext))
        ->and($draft->fresh()->search_subject)->toBe('بلاغ 03');
});

test('case and employee providers obey role and tenant scopes', function (): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    app(TenantContext::class)->setCompany($a);
    $admin = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'company_admin']);
    $hr = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'hr_staff']);
    $colleague = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'hr_staff']);
    $reporter = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'reporter']);
    $manager = User::factory()->create(['company_id' => $a->id, 'primary_role' => 'dept_manager']);
    $employee = Employee::factory()->create(['name' => 'أحمد']);
    $own = DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'opened_by_user_id' => $hr->id]);
    $other = DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id, 'opened_by_user_id' => $colleague->id]);
    app(TenantContext::class)->setCompany($b);
    $foreignEmployee = Employee::factory()->create(['name' => 'أحمد']);
    DisciplinaryCase::factory()->create(['employee_id' => $foreignEmployee->id, 'branch_id' => $foreignEmployee->branch_id]);
    app(TenantContext::class)->setCompany($a);
    $search = app(GlobalSearch::class);
    expect(count($search->for($admin, 'احمد')['cases']))->toBe(2)
        ->and(count($search->for($hr, 'احمد')['cases']))->toBe(1)
        ->and($search->for($hr, 'احمد')['cases'][0]['url'])->toBe(route('cases.show', $own->id))
        ->and(count($search->for($hr, 'احمد')['employees']))->toBe(1)
        ->and($search->for($reporter, 'احمد')['cases'])->toBe([])
        ->and($search->for($reporter, 'احمد')['employees'])->toBe([])
        ->and($search->for($manager, 'احمد')['cases'])->toBe([])
        ->and($search->for($admin, 'ا')['cases'])->toBe([])
        ->and(collect($search->for($admin, '٠٠')['cases'])->pluck('url'))->toContain(route('cases.show', $own->id))
        ->and($search->for($admin, '٠٠٠'.(string) $own->case_number)['cases'][0]['url'])->toBe(route('cases.show', $own->id));
    Livewire::actingAs($reporter)->test(SearchComponent::class)->assertStatus(403);
    Livewire::actingAs($manager)->test(SearchComponent::class)->assertStatus(403);
    $this->actingAs($reporter)->get('/home')->assertDontSee('Search workspace');
    expect($other->id)->not->toBe($own->id);
});

test('HR partner employee results require company opt-in', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $employee = Employee::factory()->create(['name' => 'أحمد']);
    $partner = HrPartner::factory()->create();
    DB::table('company_hr_partner')->insert(['company_id' => $company->id, 'hr_partner_id' => $partner->id, 'full_case_access' => false]);
    $viewer = User::factory()->create(['company_id' => null, 'primary_role' => 'hr_partner_staff', 'hr_partner_id' => $partner->id]);
    $search = app(GlobalSearch::class);
    expect($search->for($viewer, 'احمد')['employees'])->toBe([]);
    DB::table('company_hr_partner')->where('company_id', $company->id)->update(['full_case_access' => true]);
    expect($search->for($viewer, 'احمد')['employees'][0]['url'])->toBe(route('employees.show', $employee));
});

test('reports and email drafts share an eight result cap', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    ViolationReport::factory()->count(6)->create(['violation_type' => 'تأخير']);
    EmailDraft::factory()->count(6)->create(['subject' => 'تأخير']);
    expect(app(GlobalSearch::class)->for($admin, 'تاخير')['reports'])->toHaveCount(8);
});

test('committee members and legal reviewers cannot search employee profiles', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    Employee::factory()->create(['name' => 'أحمد']);
    $committee = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
    $firm = LawFirmPartner::factory()->create();
    $reviewer = User::factory()->create(['company_id' => null, 'primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $firm->id]);
    $search = app(GlobalSearch::class);
    expect($search->for($committee, 'احمد')['employees'])->toBe([])
        ->and($search->for($reviewer, 'احمد')['employees'])->toBe([]);
});

test('committee and reviewer case results follow their existing visibility rules', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    Notification::fake();
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    $law = Law::factory()->create();
    $employee = Employee::factory()->create(['name' => 'أحمد']);
    $visible = DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id,
        'status' => 'signed_recommendations_uploaded', 'law_version_id' => $law->id, 'opened_by_user_id' => $admin->id]);
    $hidden = DisciplinaryCase::factory()->create(['employee_id' => $employee->id, 'branch_id' => $employee->branch_id]);
    $committee = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'committee_member']);
    $member = CommitteePoolMember::factory()->create(['user_id' => $committee->id]);
    CommitteeSessionAttendance::factory()->create(['case_id' => $visible->id, 'committee_pool_member_id' => $member->id]);
    $firm = LawFirmPartner::factory()->create();
    CompanyLawFirmPartner::factory()->create(['company_id' => $company->id, 'law_firm_partner_id' => $firm->id]);
    $reviewer = User::factory()->create(['company_id' => null, 'primary_role' => 'external_legal_reviewer', 'law_firm_partner_id' => $firm->id]);
    app(ConsultationService::class)->request($visible, $admin);
    $search = app(GlobalSearch::class);
    expect(collect($search->for($committee, 'احمد')['cases'])->pluck('url')->all())->toBe([route('cases.show', $visible->id)])
        ->and(collect($search->for($reviewer, 'احمد')['cases'])->pluck('url')->all())->toBe([route('cases.show', $visible->id)]);
    expect($hidden->id)->not->toBe($visible->id);
});

test('search query count stays bounded as result count grows', function (): void {
    $company = Company::factory()->create();
    app(TenantContext::class)->setCompany($company);
    $admin = User::factory()->create(['company_id' => $company->id, 'primary_role' => 'company_admin']);
    Employee::factory()->count(12)->create(['name' => 'أحمد']);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $results = app(GlobalSearch::class)->for($admin, 'احمد');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($results['employees'])->toHaveCount(8)->and($queries)->toBeLessThanOrEqual(8);
});
