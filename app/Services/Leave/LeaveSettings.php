<?php

namespace App\Services\Leave;

use App\Models\Company;
use App\Models\Leave\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class LeaveSettings
{
    public function __construct(private AuditLogger $audit, private ReconciliationTriggers $triggers) {}

    public function setCreditExpiry(Company $company, ?int $days, string $reason, User $actor): Setting
    {
        abort_unless(auth()->id() === $actor->id && app(TenantContext::class)->companyId() === $company->id, 403);
        Gate::forUser($actor)->authorize('manageLeave', $company);
        if (($days !== null && ($days < 0 || $days > 65535)) || trim($reason) === '') {
            throw ValidationException::withMessages(['expiry' => __('messages.leave_reconciliation.invalid_expiry')]);
        }

        return DB::transaction(function () use ($company, $days, $reason, $actor): Setting {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $setting = Setting::query()->firstOrCreate(['company_id' => $company->id], ['credit_expiry_days' => null]);
            $before = $setting->credit_expiry_days;
            $setting->update(['credit_expiry_days' => $days, 'updated_by' => $actor->id]);
            $this->audit->log('leave.settings.changed', $setting, [
                'before' => ['credit_expiry_days' => $before], 'after' => ['credit_expiry_days' => $days], 'reason' => $reason,
            ], $actor);
            DB::table('employees')->where('company_id', $company->id)->orderBy('id')->select('id')
                ->chunkById(100, fn ($rows) => $this->triggers->dispatch($company->id, $rows->pluck('id')->all(), 'settings'));

            return $setting;
        });
    }
}
