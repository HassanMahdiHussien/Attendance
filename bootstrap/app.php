<?php

use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\SetTenantContext;
use App\Support\Access\Landing;
use Filament\Http\Middleware\IdentifyTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only a proxy on this machine (the demo's Cloudflare tunnel) may set X-Forwarded-*: without it every
        // generated URL is http:// behind HTTPS. A server facing the internet directly never sees 127.0.0.1.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo(fn (Request $request) => Landing::for($request->user()));
        $middleware->web(append: [SetLocale::class, EnsureAccountIsActive::class, EnforceIdleTimeout::class, SetTenantContext::class]);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: SetLocale::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: SetTenantContext::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnsureAccountIsActive::class);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: EnforceIdleTimeout::class);
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: IdentifyTenant::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
