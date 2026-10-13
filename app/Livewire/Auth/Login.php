<?php

namespace App\Livewire\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\LoginAttempts;
use App\Support\Access\Landing;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public function authenticate(LoginAttempts $attempts): void
    {
        $this->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $this->email = $attempts->normalize($this->email);
        $attempts->check($this->email);

        $valid = app(Timebox::class)->call(function (Timebox $timebox): bool {
            $credentials = ['email' => $this->email, 'password' => $this->password];
            $provider = Auth::guard()->getProvider();
            $user = $provider->retrieveByCredentials($credentials);
            $valid = $user && $provider->validateCredentials($user, $credentials)
                && $user->is_active && ($user->company_id === null || $user->company?->is_active);
            if ($valid) {
                Auth::login($user);
                $timebox->returnEarly();

                return true;
            }
            Event::dispatch(new Failed('web', $user, $credentials));

            return false;
        }, (int) config('auth.timebox_duration', 200_000));
        if (! $valid) {
            $attempts->hit($this->email);
            throw ValidationException::withMessages(['email' => __('filament-panels::auth/pages/login.messages.failed')]);
        }

        /** @var User $user */
        $user = Auth::user();
        $attempts->clear($this->email);
        session()->regenerate();
        // Filament panel scripts need a full page load before its Alpine components initialize.
        $this->redirect(Landing::for($user), navigate: $user->primary_role !== UserRole::PlatformAdmin);
    }

    public function render(): View
    {
        return view('livewire.auth.login')->layout('layouts.guest');
    }
}
