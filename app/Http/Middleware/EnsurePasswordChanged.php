<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Filament::getCurrentPanel() ? Filament::auth()->user() : $request->user();
        if (! $user?->must_change_password) {
            return $next($request);
        }

        if ($request->is('account/password') || $request->routeIs('logout', 'locale.update')
            || $this->isPasswordComponentUpdate($request)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('messages.password.change_required')], 403);
        }

        return redirect()->route('account.password');
    }

    private function isPasswordComponentUpdate(Request $request): bool
    {
        // Livewire 4 serves updates from a hashed path (/livewire-{hash}/update), so match the route name.
        if (! $request->routeIs('*livewire.update')) {
            return false;
        }

        $components = $request->input('components');
        if (! is_array($components) || count($components) !== 1) {
            return false;
        }

        $snapshot = json_decode($components[0]['snapshot'] ?? '', true);

        return ($snapshot['memo']['name'] ?? null) === 'auth.change-password';
    }
}
