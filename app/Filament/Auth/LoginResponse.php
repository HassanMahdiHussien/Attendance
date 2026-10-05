<?php

namespace App\Filament\Auth;

use App\Enums\UserRole;
use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        if ($request->user()?->primary_role === UserRole::PlatformAdmin) {
            return redirect('/platform');
        }

        return redirect()->intended(Filament::getUrl());
    }
}
