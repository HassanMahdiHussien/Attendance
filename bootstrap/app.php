<?php

use App\Http\Middleware\EnforceIdleTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SetTenantContext;
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
        $middleware->web(append: [EnsureAccountIsActive::class, EnforceIdleTimeout::class, SetTenantContext::class]);
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
