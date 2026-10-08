<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user() ?? (Filament::getCurrentPanel() ? Filament::auth()->user() : null);
        $locale = $user?->locale ?? $request->session()->get('locale', 'en');
        app()->setLocale(in_array($locale, ['en', 'ar'], true) ? $locale : 'en');

        return $next($request);
    }
}
