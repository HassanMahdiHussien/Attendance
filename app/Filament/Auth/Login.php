<?php

namespace App\Filament\Auth;

use App\Services\Auth\LoginAttempts;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\View;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function getFormContentComponent(): Component
    {
        return Group::make([
            parent::getFormContentComponent(),
            View::make('filament.partials.locale-switch'),
        ]);
    }

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
