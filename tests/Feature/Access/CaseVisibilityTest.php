<?php

use App\Enums\UserRole;
use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
use App\Models\User;
use App\Support\Access\CaseVisibility;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

dataset('visibility roles', [
    'platform admin' => ['platform', ['a_opened', 'a_assigned', 'a_attended', 'a_requested', 'a_other', 'b_requested', 'b_partner', 'b_other']],
    'company admin' => ['admin', ['a_opened', 'a_assigned', 'a_attended', 'a_requested', 'a_other']],
    'HR staff' => ['hr', ['a_opened', 'a_assigned']],
    'HR staff with add-on' => ['hr_addon', ['a_opened', 'a_assigned', 'a_attended', 'a_requested', 'a_other']],
    'committee member' => ['committee', ['a_attended']],
    'department manager' => ['department', []],
    'reporter' => ['reporter', []],
    'external legal reviewer' => ['reviewer', ['a_requested']],
    'HR partner staff' => ['partner', ['b_requested', 'b_partner', 'b_other']],
]);

test('each role sees exactly its contracted case IDs in a mixed two-company dataset', function (string $actorKey, array $expectedKeys): void {
    $a = Company::factory()->create();
    $b = Company::factory()->create();
    $lawFirm = LawFirmPartner::factory()->create();
    $hrPartner = HrPartner::factory()->create();
    $users = [
        'platform' => User::factory()->create(),
        'admin' => User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::CompanyAdmin]),
        'hr' => User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::HrStaff]),
        'hr_addon' => User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::HrStaff]),
        'committee' => User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::CommitteeMember]),
        'department' => User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::DeptManager]),
        'reporter' => User::factory()->create(['company_id' => $a->id, 'primary_role' => UserRole::Reporter]),
        'reviewer' => User::factory()->create(['primary_role' => UserRole::ExternalLegalReviewer, 'law_firm_partner_id' => $lawFirm->id]),
        'partner' => User::factory()->create(['primary_role' => UserRole::HrPartnerStaff, 'hr_partner_id' => $hrPartner->id]),
    ];
    $users['hr_addon']->givePermissionTo('view_all_company_cases');
    DB::table('company_law_firm_partner')->insert(['company_id' => $a->id, 'law_firm_partner_id' => $lawFirm->id]);
    DB::table('company_hr_partner')->insert([
        ['company_id' => $a->id, 'hr_partner_id' => $hrPartner->id, 'full_case_access' => false],
        ['company_id' => $b->id, 'hr_partner_id' => $hrPartner->id, 'full_case_access' => true],
    ]);

    $context = app(TenantContext::class);
    $cases = [];
    $cases['a_opened'] = $context->runForCompany($a, fn () => DisciplinaryCase::factory()->create(['opened_by_user_id' => $users['hr']->id]));
    $cases['a_assigned'] = $context->runForCompany($a, fn () => DisciplinaryCase::factory()->create(['assigned_hr_user_id' => $users['hr']->id]));
    $cases['a_attended'] = $context->runForCompany($a, fn () => DisciplinaryCase::factory()->create());
    $cases['a_requested'] = $context->runForCompany($a, fn () => DisciplinaryCase::factory()->create(['legal_review_requested_at' => now()]));
    $cases['a_other'] = $context->runForCompany($a, fn () => DisciplinaryCase::factory()->create());
    $cases['b_requested'] = $context->runForCompany($b, fn () => DisciplinaryCase::factory()->create(['legal_review_requested_at' => now()]));
    $cases['b_partner'] = $context->runForCompany($b, fn () => DisciplinaryCase::factory()->create());
    $cases['b_other'] = $context->runForCompany($b, fn () => DisciplinaryCase::factory()->create());
    $context->runForCompany($a, function () use ($a, $users, $cases): void {
        $member = CommitteePoolMember::factory()->create(['company_id' => $a->id, 'user_id' => $users['committee']->id]);
        CommitteeSessionAttendance::factory()->create(['case_id' => $cases['a_attended']->id, 'committee_pool_member_id' => $member->id]);
    });

    $context->setCompany($a);
    try {
        $actual = CaseVisibility::for($users[$actorKey])->pluck('id')->sort()->values()->all();
        $expected = collect($expectedKeys)->map(fn (string $key) => $cases[$key]->id)->sort()->values()->all();
        expect($actual)->toBe($expected);
    } finally {
        $context->clear();
    }
})->with('visibility roles');
