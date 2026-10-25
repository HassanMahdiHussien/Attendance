<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\NotificationMessage;
use App\Models\Attendance\NotificationMessageCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationMessageCase> */
class NotificationMessageCaseFactory extends Factory
{
    protected $model = NotificationMessageCase::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'message_id' => NotificationMessage::factory(), 'case_id' => FollowUpCase::factory(),
            'route_key' => 'manager', 'cycle_number' => 1,
            'kind' => 'first', 'snapshot' => ['items' => []], 'created_at' => now(),
        ];
    }
}
