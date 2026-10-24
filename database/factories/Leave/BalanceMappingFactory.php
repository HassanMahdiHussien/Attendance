<?php

namespace Database\Factories\Leave;

use App\Models\Leave\BalanceMapping;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BalanceMapping> */
class BalanceMappingFactory extends Factory
{
    protected $model = BalanceMapping::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'header_signature' => hash('sha256', $this->faker->uuid()), 'headers' => [], 'mapping' => []];
    }
}
