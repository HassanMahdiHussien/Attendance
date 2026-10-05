<?php

namespace App\Services\Auth;

use App\Services\AuditLogger;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class LoginAttempts
{
    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function check(string $email, string $field = 'email'): void
    {
        foreach ($this->keys($email) as $key) {
            if (RateLimiter::tooManyAttempts($key, 5)) {
                app(AuditLogger::class)->log('auth.throttled', metadata: ['email' => $email, 'ip' => request()->ip()]);
                throw ValidationException::withMessages([$field => __('filament-panels::auth/pages/login.messages.failed')]);
            }
        }
    }

    public function hit(string $email): void
    {
        foreach ($this->keys($email) as $key) {
            RateLimiter::hit($key, 60);
        }
    }

    public function clear(string $email): void
    {
        foreach ($this->keys($email) as $key) {
            RateLimiter::clear($key);
        }
    }

    private function keys(string $email): array
    {
        return ['login-email:'.hash('sha256', $email), 'login-ip:'.hash('sha256', (string) request()->ip())];
    }
}
