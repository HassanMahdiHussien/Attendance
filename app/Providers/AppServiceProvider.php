<?php

namespace App\Providers;

use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Relation;
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
