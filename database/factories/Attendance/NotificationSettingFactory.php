<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\NotificationSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationSetting> */
class NotificationSettingFactory extends Factory
{
    protected $model = NotificationSetting::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'mode' => 'review', 'build_time' => '09:00:00',
            'reminder_interval_days' => 2, 'reminder_max' => 3,
            'language' => 'en',
        ];
    }
}
