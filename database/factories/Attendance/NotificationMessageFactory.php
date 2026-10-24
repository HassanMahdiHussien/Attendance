<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\NotificationBatch;
use App\Models\Attendance\NotificationMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NotificationMessage> */
class NotificationMessageFactory extends Factory
{
    protected $model = NotificationMessage::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'batch_id' => NotificationBatch::factory(), 'uuid' => (string) Str::uuid(),
            'recipient_kind' => 'manager', 'address' => fake()->unique()->safeEmail(),
            'language' => 'en', 'status' => 'draft',
        ];
    }
}
