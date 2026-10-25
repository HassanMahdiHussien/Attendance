<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Events\AttendanceProcessed;
use App\Events\CoverageChanged;
use App\Events\DaysAssessed;
use App\Events\EmployeeExitChanged;
use App\Events\EmployeeVersionChanged;
use App\Filament\Auth\LoginResponse;
use App\Listeners\AuditAuthEvents;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\ImportBatch;
use App\Models\Company;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\ImportBatch as LeaveImportBatch;
use App\Policies\Attendance\AttendanceExportPolicy;
use App\Policies\Attendance\ImportBatchPolicy as AttendanceImportBatchPolicy;
use App\Policies\Leave\BalanceBatchPolicy;
use App\Policies\Leave\ImportBatchPolicy as LeaveImportBatchPolicy;
use App\Services\Attendance\Ai\ChatModel;
use App\Services\Attendance\Ai\ClaudeChatModel;
use App\Services\Attendance\Ai\HolidayClient;
use App\Services\Attendance\AssessmentTriggers;
use App\Services\Attendance\EmployeeChangeListener;
use App\Services\Attendance\FollowUp\CaseSync;
use App\Services\Leave\ReconciliationTriggers;
use App\Services\Leave\UnknownEmployeeIssues;
use App\Support\HealthPing;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Events\BackupManifestWasCreated;
use Spatie\Backup\Events\BackupWasSuccessful;
use Spatie\Backup\Events\DumpingDatabase;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, fn () => new TenantContext);
        $this->app->bind(HolidayClient::class, fn () => new HolidayClient);
        $this->app->bind(ChatModel::class, ClaudeChatModel::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(ImportBatch::class, AttendanceImportBatchPolicy::class);
        Gate::policy(AttendanceExport::class, AttendanceExportPolicy::class);
        Gate::policy(LeaveImportBatch::class, LeaveImportBatchPolicy::class);
        Gate::policy(BalanceBatch::class, BalanceBatchPolicy::class);
        $guardBackup = function (): void {
            if (! app()->environment(['local', 'testing']) && mb_strlen((string) config('backup.backup.password')) < 32) {
                throw new \RuntimeException('A backup archive password of at least 32 characters is required.');
            }
            if (! app()->environment(['local', 'testing'])
                && ! filter_var(config('displanery.backups.notify_email'), FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('A backup notification email is required.');
            }
            if (in_array('backup-sftp', config('backup.backup.destination.disks', []), true)
                && ! config('filesystems.disks.backup-sftp.hostFingerprint')) {
                throw new \RuntimeException('A pinned backup SFTP host fingerprint is required.');
            }
        };
        Event::listen(DumpingDatabase::class, $guardBackup);
        Event::listen(BackupManifestWasCreated::class, $guardBackup);
        Event::listen(BackupWasSuccessful::class, function (BackupWasSuccessful $event): void {
            if ($event->diskName === last(config('backup.backup.destination.disks', []))) {
                app(HealthPing::class)->success('backup');
            }
        });
        Event::listen(BackupHasFailed::class, fn () => app(HealthPing::class)->failure('backup', 'Backup failed'));
        Event::listen(EmployeeVersionChanged::class, [EmployeeChangeListener::class, 'handle']);
        Event::listen(EmployeeVersionChanged::class, [CaseSync::class, 'employeeVersionChanged']);
        Event::listen(EmployeeVersionChanged::class, [AssessmentTriggers::class, 'employeeVersionChanged']);
        Event::listen(EmployeeVersionChanged::class, [UnknownEmployeeIssues::class, 'employeeVersionChanged']);
        Event::listen(EmployeeExitChanged::class, [AssessmentTriggers::class, 'employeeExitChanged']);
        Event::listen(AttendanceProcessed::class, [AssessmentTriggers::class, 'attendanceProcessed']);
        Event::listen(CoverageChanged::class, [AssessmentTriggers::class, 'coverageChanged']);
        Event::listen(DaysAssessed::class, [ReconciliationTriggers::class, 'daysAssessed']);
        Gate::define('importEmployees', fn ($user, Company $company): bool => $user->is_active
            && in_array($user->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && Filament::getTenant() instanceof Company
            && (int) Filament::getTenant()->id === (int) $company->id
            && $user->canAccessTenant($company));
        Gate::define('manageLeave', fn ($user, Company $company): bool => $user->is_active
            && in_array($user->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && app(TenantContext::class)->companyId() === $company->id
            && $user->canAccessTenant($company));
        Gate::define('sendAttendanceNotifications', fn ($user, Company $company): bool => $user->is_active
            && in_array($user->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && app(TenantContext::class)->companyId() === $company->id
            && $user->canAccessTenant($company));
        $this->app->bind(\Filament\Auth\Http\Responses\Contracts\LoginResponse::class, LoginResponse::class);
        foreach ([Login::class, Logout::class, Failed::class] as $event) {
            Event::listen($event, [AuditAuthEvents::class, 'handle']);
        }
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
