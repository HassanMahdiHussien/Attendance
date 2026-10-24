<?php

namespace Database\Factories\Leave;

use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceBatchEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BalanceBatchEvent> */
class BalanceBatchEventFactory extends Factory
{
    protected $model = BalanceBatchEvent::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'batch_id' => BalanceBatch::factory(), 'sequence' => $this->faker->unique()->numberBetween(1, 1000000),
            'state' => 'completed'];
    }
}
