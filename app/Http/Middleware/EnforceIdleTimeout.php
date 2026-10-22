<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = (Filament::getCurrentPanel() ? Filament::auth()->user() : $request->user())) {
            $minutes = $user->primary_role === UserRole::PlatformAdmin
                ? config('displanery.idle_timeout_minutes.platform') : config('displanery.idle_timeout_minutes.default');
            $last = $request->session()->get('last_activity_at');
            if ($last !== null && now()->timestamp - (int) $last > $minutes * 60) {
                (Filament::getCurrentPanel() ? Filament::auth() : Auth::guard())->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                if ($request->expectsJson()) {
                    throw new HttpResponseException(response()->json(['message' => 'Unauthenticated.'], 401));
                }

                return redirect(Filament::getCurrentPanel()?->getLoginUrl() ?? '/login');
            }
            $request->session()->put('last_activity_at', now()->timestamp);
        }

        return $next($request);
    }
}
