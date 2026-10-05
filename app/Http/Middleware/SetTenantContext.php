<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(TenantContext::class);
        $context->clear();
        $user = Filament::getCurrentPanel() ? Filament::auth()->user() : $request->user();

        if ($user?->primary_role === UserRole::PlatformAdmin) {
            $context->setAllCompanies();
        } elseif ($user?->company_id !== null) {
            $context->setCompany($user->company_id);
        }

        return $next($request);
    }
}
