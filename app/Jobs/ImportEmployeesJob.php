<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\EmployeeImportRun;
use App\Models\User;
use App\Services\Employees\EmployeeImport;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImportEmployeesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct(public int $runId) {}

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    public function handle(EmployeeImport $import): void
    {
        $companyId = DB::table('employee_import_runs')->where('id', $this->runId)->value('company_id');
        $company = Company::findOrFail($companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $import): void {
            $run = EmployeeImportRun::query()->findOrFail($this->runId);
            if ($run->status === 'completed') {
                return;
            }
            $shouldProcess = DB::transaction(function () use ($run): bool {
                $locked = EmployeeImportRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                if ($locked->status === 'completed') {
                    return false;
                }
                $locked->update(['status' => 'processing', 'error' => null]);

                return true;
            });
            if (! $shouldProcess) {
                return;
            }
            $previousActor = Auth::user();
            $previousTenant = Filament::getTenant();
            $previousLocale = App::getLocale();
            try {
                $actor = User::findOrFail($run->uploaded_by);
                // A queue worker has no signed-in user, and Filament needs one before a tenant can be set.
                Auth::setUser($actor);
                Filament::setTenant($company);
                // The preview token covers the translated row messages, so recheck in the language the preview was made in.
                App::setLocale($actor->preferredLocale());
                Gate::forUser($actor)->authorize('importEmployees', $company);
                $path = Storage::disk('local')->path($run->file_path);
                if (! is_file($path) || ! hash_equals($run->file_sha256, hash_file('sha256', $path))) {
                    throw new RuntimeException('stored_file_changed');
                }
                $file = new UploadedFile($path, $run->file_name, null, null, true);
                DB::transaction(function () use ($run, $company, $file, $actor, $import): void {
                    $locked = EmployeeImportRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
                    if ($locked->status === 'completed') {
                        return;
                    }
                    $result = $locked->kind === 'mapped'
                        ? $import->commitMapped($company, $file, $actor, $locked->mode, $locked->effective_date->toDateString(), $locked->mapping, $locked->token_or_hash, $locked->file_path)
                        : $import->commit($company, $file, $actor, $locked->token_or_hash, $locked->file_path);
                    if (! $result['committed'] && ! ($result['alreadyImported'] ?? false)) {
                        throw new RuntimeException('preview_changed');
                    }
                    $counts = $locked->kind === 'mapped' ? $result['batch']->counts
                        : ['added' => $result['added'] ?? 0, 'updated' => $result['updated'] ?? 0];
                    $locked->update(['status' => 'completed', 'employee_import_batch_id' => $result['batch']->id,
                        'result_counts' => $counts, 'error' => null]);
                });
            } catch (\Throwable $exception) {
                $code = match (true) {
                    $exception instanceof AuthorizationException => 'permission_revoked',
                    $exception instanceof RuntimeException && $exception->getMessage() === 'stored_file_changed' => 'stored_file_changed',
                    $exception instanceof RuntimeException && $exception->getMessage() === 'preview_changed' => 'preview_changed',
                    default => 'processing_failed',
                };
                $run->refresh()->update(['status' => 'failed', 'error' => $code]);
                report($exception);
            } finally {
                App::setLocale($previousLocale);
                Filament::setTenant($previousTenant);
                if ($previousActor !== null) {
                    Auth::setUser($previousActor);
                } else {
                    Auth::guard()->forgetUser();
                }
            }
        });
    }

    public function failed(\Throwable $exception): void
    {
        $companyId = DB::table('employee_import_runs')->where('id', $this->runId)->value('company_id');
        if ($companyId === null) {
            return;
        }
        app(TenantContext::class)->runForCompany((int) $companyId, function (): void {
            EmployeeImportRun::query()->whereKey($this->runId)->where('status', '!=', 'completed')
                ->update(['status' => 'failed', 'error' => 'processing_failed']);
        });
        report($exception);
    }
}
