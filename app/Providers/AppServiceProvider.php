<?php

namespace App\Providers;

use App\Ai\ClaudeClient;
use App\Ai\SdkClaudeClient;
use App\Enums\UserRole;
use App\Filament\Auth\LoginResponse;
use App\LegalLibrary\ClaudePageReader;
use App\LegalLibrary\PageReader;
use App\Listeners\AuditAuthEvents;
use App\Models\Company;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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
        $this->app->bind(PageReader::class, ClaudePageReader::class);
        $this->app->bind(ClaudeClient::class, SdkClaudeClient::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('importEmployees', fn ($user, Company $company): bool => $user->is_active
            && in_array($user->primary_role, [UserRole::CompanyAdmin, UserRole::PlatformAdmin], true)
            && Filament::getTenant() instanceof Company
            && (int) Filament::getTenant()->id === (int) $company->id
            && $user->canAccessTenant($company));
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, LoginResponse::class);
        foreach ([Login::class, Logout::class, Failed::class] as $event) {
            Event::listen($event, [AuditAuthEvents::class, 'handle']);
        }
        Relation::morphMap([
            'violation_report' => ViolationReport::class,
            'case' => DisciplinaryCase::class,
            'document' => Document::class,
        ]);
        // Worker jobs must never inherit another job's tenant. Sync jobs run inside the caller's
        // request, so clearing there would wipe the caller's own context; jobs scope themselves.
        $clearForWorkerJobs = function ($event): void {
            if ($event->connectionName !== 'sync') {
                app(TenantContext::class)->clear();
            }
        };
        Queue::before($clearForWorkerJobs);
        Queue::after($clearForWorkerJobs);
        Queue::failing($clearForWorkerJobs);
    }
}
