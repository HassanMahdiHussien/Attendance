<?php

namespace App\Filament\Auth;

use App\Services\Auth\LoginAttempts;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $attempts = app(LoginAttempts::class);
        $email = $attempts->normalize((string) ($this->data['email'] ?? ''));
        $this->data['email'] = $email;
        $attempts->check($email, 'data.email');

        try {
            $response = parent::authenticate();
            if ($response !== null) {
                $attempts->clear($email);
            }

            return $response;
        } catch (ValidationException $exception) {
            $attempts->hit($email);
            throw $exception;
        }
    }
}
