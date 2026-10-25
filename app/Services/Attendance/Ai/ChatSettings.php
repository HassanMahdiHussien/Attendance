<?php

namespace App\Services\Attendance\Ai;

use App\Models\Attendance\ChatSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ChatSettings
{
    public function __construct(private AuditLogger $audit) {}

    public function enabled(Company $company): bool
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);

        return (bool) ChatSetting::query()->where('company_id', $company->id)->value('chat_changes_enabled');
    }

    public function setEnabled(Company $company, bool $enabled, User $actor, string $reason): ChatSetting
    {
        abort_unless(auth()->id() === $actor->id && app(TenantContext::class)->companyId() === $company->id
            && trim($reason) !== '', 403);
        Gate::forUser($actor)->authorize('update', $company);

        return DB::transaction(function () use ($company, $enabled, $actor, $reason): ChatSetting {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $setting = ChatSetting::query()->firstOrCreate(['company_id' => $company->id],
                ['chat_changes_enabled' => false]);
            if ($setting->chat_changes_enabled !== $enabled) {
                $before = $setting->chat_changes_enabled;
                $setting->update(['chat_changes_enabled' => $enabled, 'updated_by' => $actor->id]);
                $this->audit->log('attendance.chat_settings.changed', $setting,
                    ['before' => $before, 'after' => $enabled, 'reason' => $reason], $actor);
            }

            return $setting;
        });
    }
}
