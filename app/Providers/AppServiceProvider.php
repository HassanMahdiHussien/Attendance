<?php

namespace App\Providers;

use App\Filament\Auth\LoginResponse;
use App\Listeners\AuditAuthEvents;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(RequestHandled::class, fn () => app(TenantContext::class)->clear());
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, LoginResponse::class);
        foreach ([Login::class, Logout::class, Failed::class] as $event) {
            Event::listen($event, [AuditAuthEvents::class, 'handle']);
        }
        Relation::morphMap([
            'violation_report' => ViolationReport::class,
            'case' => DisciplinaryCase::class,
            'document' => Document::class,
        ]);
        Queue::before(fn () => app(TenantContext::class)->clear());
        Queue::after(fn () => app(TenantContext::class)->clear());
        Queue::failing(fn () => app(TenantContext::class)->clear());
    }
}
