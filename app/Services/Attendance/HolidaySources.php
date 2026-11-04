<?php

namespace App\Services\Attendance;

use App\Jobs\Attendance\ReadHolidaySourceJob;
use App\Models\Attendance\HolidaySource;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HolidaySources
{
    public function __construct(private AuditLogger $audit) {}

    public function upload(Company $company, UploadedFile $file, User $actor): HolidaySource
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id && auth()->id() === $actor->id
            && $actor->is_active && $actor->canAccessTenant($company), 403);
        Gate::forUser($actor)->authorize('update', $company);
        $extension = strtolower($file->getClientOriginalExtension());
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        if (! in_array($extension, ['pdf', 'png', 'jpg', 'jpeg', 'eml', 'msg'], true)
            || $file->getSize() > 25 * 1024 * 1024 || $file->getSize() === 0 || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['file' => __('messages.holidays.ai_errors.unsupported_file')]);
        }
        $sha = hash_file('sha256', $file->getRealPath());
        $existing = HolidaySource::query()->where('company_id', $company->id)->where('sha256', $sha)->first();
        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($company, $file, $actor, $sha, $extension, $name): HolidaySource {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $existing = HolidaySource::query()->where('company_id', $company->id)->where('sha256', $sha)->first();
            if ($existing) {
                return $existing;
            }
            $path = 'attendance/holidays/'.$company->id.'/originals/'.$sha.'.'.$extension;
            if (! Storage::disk('local')->putFileAs(dirname($path), $file, basename($path))) {
                throw ValidationException::withMessages(['file' => __('messages.holidays.ai_errors.read_failed')]);
            }
            $source = HolidaySource::create([
                'company_id' => $company->id,
                'uploaded_by' => $actor->id,
                'file_path' => $path,
                'file_name' => $name,
                'mime' => $file->getMimeType() ?: 'application/octet-stream',
                'sha256' => $sha,
                'ai_status' => 'pending',
            ]);
            $this->audit->log('attendance.holiday_source.uploaded', $source,
                ['source_id' => $source->id, 'sha256' => $sha, 'mime' => $source->mime, 'size' => $file->getSize()], $actor);
            ReadHolidaySourceJob::dispatch($company->id, $source->id)->afterCommit();

            return $source;
        });
    }

    public function retry(HolidaySource $source, User $actor): void
    {
        $company = Company::findOrFail($source->company_id);
        abort_unless(app(TenantContext::class)->companyId() === $company->id && auth()->id() === $actor->id
            && $actor->is_active && $actor->canAccessTenant($company), 403);
        Gate::forUser($actor)->authorize('update', $company);
        abort_unless(in_array($source->ai_status, ['failed', 'unavailable'], true)
            || ($source->ai_status === 'pending' && $source->updated_at->lt(now()->subMinutes(10))), 409);
        $source->update(['ai_status' => 'pending', 'error_code' => null]);
        ReadHolidaySourceJob::dispatch($company->id, $source->id)->afterCommit();
    }

    public function close(HolidaySource $source, User $actor): void
    {
        $company = Company::findOrFail($source->company_id);
        abort_unless(app(TenantContext::class)->companyId() === $company->id && auth()->id() === $actor->id
            && $actor->is_active && $actor->canAccessTenant($company), 403);
        Gate::forUser($actor)->authorize('update', $company);
        DB::transaction(function () use ($source, $actor): void {
            $source = HolidaySource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            abort_unless($source->closed_at === null, 409);
            $source->update(['closed_at' => now(), 'closed_by' => $actor->id]);
            $this->audit->log('attendance.holiday_source.closed', $source, ['source_id' => $source->id], $actor);
        });
    }

    public function reopen(HolidaySource $source, User $actor): void
    {
        $company = Company::findOrFail($source->company_id);
        abort_unless(app(TenantContext::class)->companyId() === $company->id && auth()->id() === $actor->id
            && $actor->is_active && $actor->canAccessTenant($company), 403);
        Gate::forUser($actor)->authorize('update', $company);
        DB::transaction(function () use ($source, $actor): void {
            $source = HolidaySource::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();
            abort_unless($source->closed_at !== null, 409);
            $source->update(['closed_at' => null, 'closed_by' => null]);
            $this->audit->log('attendance.holiday_source.reopened', $source, ['source_id' => $source->id], $actor);
        });
    }
}
