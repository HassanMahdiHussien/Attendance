<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\FollowUpCase;
use App\Models\Attendance\NotificationRoute;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotificationRoute> */
class NotificationRouteFactory extends Factory
{
    protected $model = NotificationRoute::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => FollowUpCase::factory(), 'route_key' => 'manager',
            'sent_count' => 0,
        ];
    }
}
