<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ChatSetting;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChatSetting> */
class ChatSettingFactory extends Factory
{
    protected $model = ChatSetting::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'chat_changes_enabled' => false];
    }
}
