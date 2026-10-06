<?php

use App\Enums\UserRole;
use App\Filament\Auth\Login;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|'.request()->ip()));
    RateLimiter::clear('login-ip:'.hash('sha256', (string) request()->ip()));
});

function emailThrottleKey(string $email): string
{
    return 'login-email:'.hash('sha256', mb_strtolower(trim($email)));
}

test('five failures block the correct password and record a safe throttle audit', function (): void {
    $user = User::factory()->create();
    Filament::setCurrentPanel('platform');
    $key = emailThrottleKey($user->email);
    RateLimiter::clear($key);
    for ($i = 0; $i < 5; $i++) {
        Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'wrong-secret'])->call('authenticate')->assertHasFormErrors(['email']);
    }
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);
    expect(auth()->check())->toBeFalse();
    expect(AuditLog::where('action', 'auth.failed')->count())->toBeGreaterThanOrEqual(5);
    expect(AuditLog::where('action', 'auth.throttled')->count())->toBe(1);
    foreach (AuditLog::all() as $log) {
        $metadata = json_encode($log->metadata);
        expect($metadata)->not->toContain('wrong-secret')->not->toContain('"password"');
    }
});

test('trimmed lower-case email shares one throttle bucket', function (): void {
    $user = User::factory()->create();
    Filament::setCurrentPanel('platform');
    $key = emailThrottleKey($user->email);
    RateLimiter::clear($key);
    Livewire::test(Login::class)->fillForm(['email' => ' '.strtoupper($user->email).' ', 'password' => 'wrong'])->call('authenticate')->assertHasFormErrors(['email']);
    expect(RateLimiter::attempts($key))->toBe(1);
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'wrong'])->call('authenticate')->assertHasFormErrors(['email']);
    expect(RateLimiter::attempts($key))->toBe(2);
});

test('role refusal counts as failure and a success clears the bucket', function (): void {
    $company = Company::factory()->create();
    $reporter = User::factory()->create(['company_id' => $company->id, 'primary_role' => UserRole::Reporter]);
    Filament::setCurrentPanel('platform');
    $roleKey = emailThrottleKey($reporter->email);
    RateLimiter::clear($roleKey);
    Livewire::test(Login::class)->fillForm(['email' => $reporter->email, 'password' => 'password'])->call('authenticate')->assertHasFormErrors(['email']);
    expect(RateLimiter::attempts($roleKey))->toBe(1);

    $admin = User::factory()->create();
    $successKey = emailThrottleKey($admin->email);
    RateLimiter::hit($successKey, 60);
    Livewire::test(Login::class)->fillForm(['email' => $admin->email, 'password' => 'password'])->call('authenticate')->assertHasNoFormErrors();
    expect(RateLimiter::attempts($successKey))->toBe(0);
});

test('the component also limits repeated failures from one IP across emails', function (): void {
    $user = User::factory()->create();
    Filament::setCurrentPanel('platform');
    for ($i = 0; $i < 5; $i++) {
        Livewire::test(Login::class)
            ->fillForm(['email' => "unknown{$i}@example.test", 'password' => 'wrong'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
    }
    Livewire::test(Login::class)
        ->fillForm(['email' => $user->email, 'password' => 'password'])
        ->call('authenticate');
    expect(auth()->check())->toBeFalse();
});
