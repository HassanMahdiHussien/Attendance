<?php

namespace App\Services\Attendance\FollowUp;

use App\Models\Attendance\NotificationSetting;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class NotificationSettings
{
    public function __construct(private AuditLogger $audit) {}

    public function forCompany(Company $company): NotificationSetting
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);

        return NotificationSetting::query()->firstOrCreate(['company_id' => $company->id], [
            'mode' => 'review', 'build_time' => '09:00:00', 'reminder_interval_days' => 2,
            'reminder_max' => 3, 'language' => 'en',
        ]);
    }

    public function set(Company $company, array $values, User $actor): NotificationSetting
    {
        abort_unless(auth()->id() === $actor->id && app(TenantContext::class)->companyId() === $company->id, 403);
        Gate::forUser($actor)->authorize('sendAttendanceNotifications', $company);
        $values = Validator::make($values, [
            'mode' => 'required|in:review,auto', 'build_time' => 'required|date_format:H:i',
            'reminder_interval_days' => 'required|integer|min:1|max:365',
            'reminder_max' => 'required|integer|min:0|max:100', 'language' => 'required|in:en,ar',
            'reason' => 'required|string|max:500',
        ])->validate();

        return DB::transaction(function () use ($company, $values, $actor): NotificationSetting {
            Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
            $setting = $this->forCompany($company);
            $before = $setting->only(['mode', 'build_time', 'reminder_interval_days', 'reminder_max', 'language']);
            $after = [
                'mode' => $values['mode'], 'build_time' => $values['build_time'].':00',
                'reminder_interval_days' => $values['reminder_interval_days'],
                'reminder_max' => $values['reminder_max'], 'language' => $values['language'],
            ];
            if ($before != $after) {
                $setting->update($after + ['updated_by' => $actor->id]);
                $this->audit->log('attendance.notification_settings.changed', $setting, [
                    'before' => $before, 'after' => $after, 'reason' => $values['reason'],
                ], $actor);
            }

            return $setting;
        });
    }
}
