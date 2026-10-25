<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\NotificationAttempt;
use App\Models\Attendance\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationAttempt> */
class NotificationAttemptFactory extends Factory
{
    protected $model = NotificationAttempt::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'message_id' => NotificationMessage::factory(), 'attempt_number' => 1,
            'result' => 'started', 'address' => fake()->safeEmail(),
            'started_at' => now(), 'created_at' => now(),
        ];
    }
}
