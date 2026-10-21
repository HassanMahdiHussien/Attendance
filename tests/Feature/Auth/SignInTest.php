<?php

use App\Enums\UserRole;
use App\Livewire\Auth\Login;
use App\Models\Company;
use App\Models\User;
use App\Support\Access\Landing;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    RateLimiter::clear('login-ip:'.hash('sha256', (string) request()->ip()));
});

function signInUser(UserRole $role, ?Company $company = null): User
{
    $attributes = ['primary_role' => $role];
    if ($role === UserRole::HrStaff) {
        $attributes['company_id'] = ($company ?? Company::factory()->create())->id;
    }

    return User::factory()->create($attributes);
}

dataset('panel access', [
    'platform admin platform' => [UserRole::PlatformAdmin, 'platform', true],
    'platform admin admin' => [UserRole::PlatformAdmin, 'admin', true],
    'HR platform' => [UserRole::HrStaff, 'platform', false],
    'HR admin' => [UserRole::HrStaff, 'admin', true],
]);

test('every role has exactly its contracted panel access', function (UserRole $role, string $panel, bool $allowed): void {
    $user = signInUser($role);
    Filament::setCurrentPanel($panel);
    expect($user->canAccessPanel(Filament::getPanel($panel)))->toBe($allowed);
})->with('panel access');

test('every active role signs in on the single sign-in page and lands on its home', function (UserRole $role): void {
    $user = signInUser($role);

    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('authenticate')
        ->assertHasNoErrors()->assertRedirect(Landing::for($user));
    expect(auth()->id())->toBe($user->id);
})->with([[UserRole::PlatformAdmin], [UserRole::HrStaff]]);

test('a panel visited while signed out sends the visitor to the single sign-in page', function (string $path): void {
    $this->get($path)->assertRedirect('/login');
})->with(['/admin', '/platform', '/admin/login', '/platform/login']);

test('all refused credential and account states have the same public error', function (): void {
    $company = Company::factory()->create();
    $active = signInUser(UserRole::HrStaff, $company);
    $inactive = signInUser(UserRole::HrStaff, $company);
    $inactive->forceFill(['is_active' => false])->save();
    $inactiveCompany = Company::factory()->create(['is_active' => false]);
    $companyUser = signInUser(UserRole::HrStaff, $inactiveCompany);

    $attempts = [
        ['missing@example.test', 'password'],
        [$active->email, 'wrong'],
        [$inactive->email, 'password'],
        [$companyUser->email, 'password'],
    ];
    $messages = [];
    foreach ($attempts as [$email, $password]) {
        RateLimiter::clear('login-ip:'.hash('sha256', (string) request()->ip()));
        $result = Livewire::test(Login::class)->set('email', $email)->set('password', $password)->call('authenticate');
        $result->assertHasErrors(['email']);
        $messages[] = $result->errors()->first('email');
        expect(auth()->check())->toBeFalse();
    }
    expect(array_unique($messages))->toHaveCount(1);
});

test('platform admin signing in is sent to the platform panel', function (): void {
    $user = signInUser(UserRole::PlatformAdmin);
    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('authenticate')
        ->assertRedirect('/platform');
});

test('inactive users and users of inactive companies are denied by both panels', function (string $panel): void {
    $company = Company::factory()->create();
    $inactiveUser = signInUser(UserRole::HrStaff, $company);
    $inactiveUser->forceFill(['is_active' => false])->save();
    $inactiveCompany = Company::factory()->create(['is_active' => false]);
    $companyUser = signInUser(UserRole::HrStaff, $inactiveCompany);
    expect($inactiveUser->canAccessPanel(Filament::getPanel($panel)))->toBeFalse();
    expect($companyUser->canAccessPanel(Filament::getPanel($panel)))->toBeFalse();
})->with(['platform', 'admin']);

test('signing in returns to the page that required it', function (): void {
    $company = Company::factory()->create();
    $user = signInUser(UserRole::HrStaff, $company);
    $this->get('/admin/'.$company->id.'/branches')->assertRedirect('/login');

    Livewire::test(Login::class)->set('email', $user->email)->set('password', 'password')->call('authenticate')
        ->assertRedirect(url('/admin/'.$company->id.'/branches'));
});
