<?php

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CommitteePoolMember;
use App\Models\Employee;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
use App\Models\Location;
use App\Models\User;
use App\Services\CommitteeMandateService;
use App\Services\CompanyOnboardingService;
use App\Services\CompanyStatusService;
use App\Services\PartnershipService;
use App\Services\UserAccountService;
use App\Support\Tenancy\TenantContext;

test('platform and company administration actions have attributed, secret-free audit payloads', function (): void {
    $platformAdmin = User::factory()->create();
    $this->actingAs($platformAdmin);
    app(TenantContext::class)->setAllCompanies();

    $company = app(CompanyOnboardingService::class)->create(
        ['name' => 'شركة مراجعة السجل', 'subscription_status' => 'trial'],
        ['name' => 'المدير الأول', 'email' => 'audit-admin@example.test', 'password' => 'secret12345'],
        $platformAdmin,
    );
    $companyAdmin = User::query()->where('company_id', $company->id)->firstOrFail();
    app(CompanyStatusService::class)->setActive($company, false, $platformAdmin);
    app(CompanyStatusService::class)->setActive($company, true, $platformAdmin);

    $lawFirm = LawFirmPartner::factory()->create();
    $hrPartner = HrPartner::factory()->create();
    $company->update(['letterhead_address' => 'بغداد']);
    $lawFirm->update(['name' => 'مكتب محدث']);
    $hrPartner->update(['name' => 'شريك محدث']);
    $reviewer = app(UserAccountService::class)->create([
        'name' => 'مراجع',
        'email' => 'audit-reviewer@example.test',
        'password' => 'secret12345',
        'primary_role' => UserRole::ExternalLegalReviewer->value,
        'law_firm_partner_id' => $lawFirm->id,
    ], $platformAdmin);
    app(UserAccountService::class)->update($reviewer, ['is_active' => false], $platformAdmin);
    $partnerships = app(PartnershipService::class);
    $partnerships->lawFirm($company, $lawFirm->id, true, $platformAdmin);
    $partnerships->hrPartner($company, $hrPartner->id, false, $platformAdmin);
    $partnerships->hrPartner($company, $hrPartner->id, true, $platformAdmin);
    $partnerships->lawFirm($company, $lawFirm->id, false, $platformAdmin);

    expect(AuditLog::query()->where('action', 'company.created')->where('entity_id', $company->id)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'company.deactivated')->where('entity_id', $company->id)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'company.activated')->where('entity_id', $company->id)->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'partnership.attached')->count())->toBe(2);
    expect(AuditLog::query()->where('action', 'partnership.updated')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'partnership.detached')->count())->toBe(1);
    foreach ([
        ['company.updated', $company->id],
        ['lawfirmpartner.created', $lawFirm->id],
        ['lawfirmpartner.updated', $lawFirm->id],
        ['hrpartner.created', $hrPartner->id],
        ['hrpartner.updated', $hrPartner->id],
        ['user.created', $reviewer->id],
        ['user.deactivated', $reviewer->id],
    ] as [$action, $entityId]) {
        expect(AuditLog::query()->where('action', $action)->where('entity_id', $entityId)->where('actor_user_id', $platformAdmin->id)->exists())->toBeTrue();
    }

    app(TenantContext::class)->runForCompany($company, function () use ($companyAdmin, $company): void {
        $this->actingAs($companyAdmin);
        $branch = Branch::factory()->create();
        $location = Location::factory()->create(['branch_id' => $branch->id]);
        $employee = Employee::factory()->create(['branch_id' => $branch->id]);
        $branch->update(['name' => 'فرع محدث']);
        $location->update(['name' => 'موقع محدث']);
        $employee->update(['position' => 'موظف محدث']);
        $account = app(UserAccountService::class)->create([
            'company_id' => $company->id,
            'name' => 'مبلّغ',
            'email' => 'audit-reporter@example.test',
            'password' => 'secret12345',
            'primary_role' => UserRole::Reporter->value,
        ], $companyAdmin);
        app(UserAccountService::class)->setBranchScope($account, [$branch->id], $companyAdmin);
        app(UserAccountService::class)->setAddons($account, ['review_internal_regulation'], $companyAdmin);
        app(UserAccountService::class)->update($account, ['is_active' => false], $companyAdmin);
        $mandate = app(CommitteeMandateService::class)->register([
            'company_id' => $company->id,
            'order_number' => 'A-1',
            'order_date' => '2026-10-01',
            'effective_from' => '2026-10-02',
        ], $companyAdmin);
        $member = CommitteePoolMember::factory()->create(['mandate_id' => $mandate->id, 'branch_id' => $branch->id]);

        foreach ([
            ['branch.created', $branch->id],
            ['branch.updated', $branch->id],
            ['location.created', $location->id],
            ['location.updated', $location->id],
            ['employee.created', $employee->id],
            ['employee.updated', $employee->id],
            ['user.created', $account->id],
            ['user.deactivated', $account->id],
            ['user.addons_changed', $account->id],
            ['mandate.registered', $mandate->id],
            ['committeepoolmember.created', $member->id],
        ] as [$action, $entityId]) {
            $entry = AuditLog::query()->where('action', $action)->where('entity_id', $entityId)->sole();
            expect($entry->actor_user_id)->toBe($companyAdmin->id);
        }
    });

    foreach (AuditLog::query()->whereNotNull('actor_user_id')->get() as $entry) {
        $payload = $entry->metadata;
        expect(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('"password"');
        if (str_ends_with($entry->action, '.created') || str_ends_with($entry->action, '.updated')
            || in_array($entry->action, ['company.deactivated', 'company.activated', 'user.deactivated', 'mandate.registered'], true)) {
            expect($payload)->toHaveKeys(['before', 'after']);
        }
    }
});
