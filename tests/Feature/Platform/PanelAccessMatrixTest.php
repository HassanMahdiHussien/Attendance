<?php

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
use App\Models\User;
use Filament\Facades\Filament;

test('every role has the intended panel and tenant access', function (): void {
    $first = Company::factory()->create();
    $second = Company::factory()->create();
    $lawFirm = LawFirmPartner::factory()->create();
    $hrPartner = HrPartner::factory()->create();
    $platform = Filament::getPanel('platform');
    $admin = Filament::getPanel('admin');

    foreach ([
        [UserRole::PlatformAdmin, null, true, true, true],
        [UserRole::CompanyAdmin, $first->id, false, true, false],
        [UserRole::HrStaff, $first->id, false, true, false],
        [UserRole::DeptManager, $first->id, false, false, false],
        [UserRole::Reporter, $first->id, false, false, false],
        [UserRole::CommitteeMember, $first->id, false, false, false],
        [UserRole::ExternalLegalReviewer, null, false, false, false],
        [UserRole::HrPartnerStaff, null, false, false, false],
    ] as [$role, $companyId, $platformAllowed, $adminAllowed, $otherAllowed]) {
        $user = User::factory()->create([
            'primary_role' => $role,
            'company_id' => $companyId,
            'law_firm_partner_id' => $role === UserRole::ExternalLegalReviewer ? $lawFirm->id : null,
            'hr_partner_id' => $role === UserRole::HrPartnerStaff ? $hrPartner->id : null,
        ]);
        $this->actingAs($user);
        expect($user->canAccessPanel($platform))->toBe($platformAllowed);
        expect($user->canAccessPanel($admin))->toBe($adminAllowed);
        expect($user->canAccessTenant($second))->toBe($otherAllowed);
        if ($platformAllowed) {
            $this->get('/platform')->assertOk();
        } else {
            expect($this->get('/platform')->status())->toBeIn([302, 403, 404]);
        }
        if ($adminAllowed) {
            $this->get('/admin/'.$first->id)->assertOk();
        } else {
            expect($this->get('/admin/'.$first->id)->status())->toBeIn([302, 403, 404]);
        }
    }
});

test('inactive company and inactive accounts cannot enter either panel', function (): void {
    $company = Company::factory()->create(['is_active' => false]);
    $companyAdmin = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::CompanyAdmin]);
    $platformAdmin = User::factory()->create(['is_active' => false]);
    expect($companyAdmin->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();
    expect($platformAdmin->canAccessPanel(Filament::getPanel('platform')))->toBeFalse();
});
