<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetTenantFromFilament
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();
        abort_unless($tenant instanceof Company && Filament::auth()->user()?->canAccessTenant($tenant), 403);
        app(TenantContext::class)->setCompany($tenant);

        return $next($request);
    }
}
