<?php

use App\Enums\UserRole;
use App\Filament\Auth\Login;
use App\Models\Company;
use App\Models\HrPartner;
use App\Models\LawFirmPartner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|'.request()->ip()));
    RateLimiter::clear('login-ip:'.hash('sha256', (string) request()->ip()));
});

function signInUser(UserRole $role, ?Company $company = null): User
{
    $attributes = ['primary_role' => $role];
    if (in_array($role, [UserRole::CompanyAdmin, UserRole::HrStaff, UserRole::DeptManager, UserRole::Reporter, UserRole::CommitteeMember], true)) {
        $attributes['company_id'] = ($company ?? Company::factory()->create())->id;
    } elseif ($role === UserRole::ExternalLegalReviewer) {
        $attributes['law_firm_partner_id'] = LawFirmPartner::factory()->create()->id;
    } elseif ($role === UserRole::HrPartnerStaff) {
        $attributes['hr_partner_id'] = HrPartner::factory()->create()->id;
    }

    return User::factory()->create($attributes);
}

dataset('panel access', [
    'platform admin platform' => [UserRole::PlatformAdmin, 'platform', true],
    'platform admin admin' => [UserRole::PlatformAdmin, 'admin', true],
    'company admin platform' => [UserRole::CompanyAdmin, 'platform', false],
    'company admin admin' => [UserRole::CompanyAdmin, 'admin', true],
    'HR platform' => [UserRole::HrStaff, 'platform', false],
    'HR admin' => [UserRole::HrStaff, 'admin', true],
    'department manager platform' => [UserRole::DeptManager, 'platform', false],
    'department manager admin' => [UserRole::DeptManager, 'admin', false],
    'reporter platform' => [UserRole::Reporter, 'platform', false],
    'reporter admin' => [UserRole::Reporter, 'admin', false],
    'committee platform' => [UserRole::CommitteeMember, 'platform', false],
    'committee admin' => [UserRole::CommitteeMember, 'admin', false],
    'reviewer platform' => [UserRole::ExternalLegalReviewer, 'platform', false],
    'reviewer admin' => [UserRole::ExternalLegalReviewer, 'admin', false],
    'HR partner platform' => [UserRole::HrPartnerStaff, 'platform', false],
    'HR partner admin' => [UserRole::HrPartnerStaff, 'admin', false],
]);

test('every role has exactly its contracted panel access', function (UserRole $role, string $panel, bool $allowed): void {
    $user = signInUser($role);
    Filament::setCurrentPanel($panel);
    expect($user->canAccessPanel(Filament::getPanel($panel)))->toBe($allowed);

    $result = Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');
    if ($allowed) {
        $result->assertHasNoFormErrors();
        expect(auth()->id())->toBe($user->id);
    } else {
        $result->assertHasFormErrors(['email']);
        expect(auth()->check())->toBeFalse();
    }
})->with('panel access');

test('all refused credential and account states have the same public error', function (): void {
    $company = Company::factory()->create();
    $active = signInUser(UserRole::CompanyAdmin, $company);
    $inactive = signInUser(UserRole::CompanyAdmin, $company);
    $inactive->forceFill(['is_active' => false])->save();
    $wrongPanel = signInUser(UserRole::Reporter, $company);
    $inactiveCompany = Company::factory()->create(['is_active' => false]);
    $companyUser = signInUser(UserRole::CompanyAdmin, $inactiveCompany);
    Filament::setCurrentPanel('admin');

    $attempts = [
        ['missing@example.test', 'password'],
        [$active->email, 'wrong'],
        [$inactive->email, 'password'],
        [$companyUser->email, 'password'],
        [$wrongPanel->email, 'password'],
    ];
    $messages = [];
    foreach ($attempts as [$email, $password]) {
        $result = Livewire::test(Login::class)->fillForm(['email' => $email, 'password' => $password])->call('authenticate');
        $result->assertHasFormErrors(['email']);
        $messages[] = $result->errors()->first('data.email');
        expect(auth()->check())->toBeFalse();
    }
    expect(array_unique($messages))->toHaveCount(1);
});

test('platform admin signing in at admin is sent to platform', function (): void {
    $user = signInUser(UserRole::PlatformAdmin);
    Filament::setCurrentPanel('admin');
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertRedirect('/platform');
});

test('inactive users and users of inactive companies are denied by both panels', function (string $panel): void {
    $company = Company::factory()->create();
    $inactiveUser = signInUser(UserRole::CompanyAdmin, $company);
    $inactiveUser->forceFill(['is_active' => false])->save();
    $inactiveCompany = Company::factory()->create(['is_active' => false]);
    $companyUser = signInUser(UserRole::CompanyAdmin, $inactiveCompany);
    expect($inactiveUser->canAccessPanel(Filament::getPanel($panel)))->toBeFalse();
    expect($companyUser->canAccessPanel(Filament::getPanel($panel)))->toBeFalse();
})->with(['platform', 'admin']);
