<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::getCurrentPanel() ? Filament::auth()->user() : $request->user();
        if ($user && (! $user->is_active || ($user->company_id !== null && ! $user->company?->is_active))) {
            (Filament::getCurrentPanel() ? Filament::auth() : Auth::guard())->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                throw new HttpResponseException(response()->json(['message' => 'Unauthenticated.'], 401));
            }

            return redirect(Filament::getCurrentPanel()?->getLoginUrl() ?? '/admin/login');
        }

        return $next($request);
    }
}
