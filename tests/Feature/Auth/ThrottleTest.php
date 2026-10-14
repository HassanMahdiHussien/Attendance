<?php

use App\Livewire\Auth\Login;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

beforeEach(function (): void {
    RateLimiter::clear('login-ip:'.hash('sha256', (string) request()->ip()));
});

function emailThrottleKey(string $email): string
{
    return 'login-email:'.hash('sha256', mb_strtolower(trim($email)));
}

function attemptSignIn(string $email, string $password)
{
    return Livewire::test(Login::class)->set('email', $email)->set('password', $password)->call('authenticate');
}

test('five failures block the correct password and record a safe throttle audit', function (): void {
    $user = User::factory()->create();
    RateLimiter::clear(emailThrottleKey($user->email));
    for ($i = 0; $i < 5; $i++) {
        attemptSignIn($user->email, 'wrong-secret')->assertHasErrors(['email']);
    }
    attemptSignIn($user->email, 'password')->assertHasErrors(['email']);
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
    $key = emailThrottleKey($user->email);
    RateLimiter::clear($key);
    attemptSignIn(' '.strtoupper($user->email).' ', 'wrong')->assertHasErrors(['email']);
    expect(RateLimiter::attempts($key))->toBe(1);
    attemptSignIn($user->email, 'wrong')->assertHasErrors(['email']);
    expect(RateLimiter::attempts($key))->toBe(2);
});

test('a success clears the email bucket', function (): void {
    $user = User::factory()->create();
    $key = emailThrottleKey($user->email);
    RateLimiter::hit($key, 60);
    attemptSignIn($user->email, 'password')->assertHasNoErrors();
    expect(RateLimiter::attempts($key))->toBe(0);
});

test('repeated failures from one IP across emails are limited', function (): void {
    $user = User::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        attemptSignIn("unknown{$i}@example.test", 'wrong')->assertHasErrors(['email']);
    }
    attemptSignIn($user->email, 'password');
    expect(auth()->check())->toBeFalse();
});
