<?php

namespace App\Filament\Auth;

use App\Services\AuditLogger;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $email = mb_strtolower(trim((string) ($this->data['email'] ?? '')));
        $this->data['email'] = $email;
        $key = 'login-email:'.hash('sha256', $email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            app(AuditLogger::class)->log('auth.throttled', metadata: ['email' => $email, 'ip' => request()->ip()]);
            $this->throwFailureValidationException();
        }

        try {
            $response = parent::authenticate();
            if ($response !== null) {
                RateLimiter::clear($key);
            }

            return $response;
        } catch (ValidationException $exception) {
            RateLimiter::hit($key, 60);
            throw $exception;
        }
    }
}
