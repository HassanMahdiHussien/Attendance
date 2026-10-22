<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Policy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Policy> */
class PolicyFactory extends Factory
{
    protected $model = Policy::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'effective_from' => today()->toDateString(),
            'office_start' => '08:00:00',
            'flex_minutes' => 60,
            'workday_minutes' => 540,
            'min_presence_minutes' => 480,
            'close_grace_minutes' => 120,
            'exits_count_toward_balance' => false,
            'office_rest_days' => [5, 6],
            'version' => 100000 + random_int(1, 1000000),
        ];
    }
}
