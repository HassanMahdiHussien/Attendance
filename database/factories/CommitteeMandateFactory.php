<?php

namespace Database\Factories;

use App\Models\CommitteeMandate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommitteeMandate> */
class CommitteeMandateFactory extends Factory
{
    protected $model = CommitteeMandate::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'order_number' => $this->faker->words(3, true),
            'order_date' => now(),
            'effective_from' => now(),
        ];
    }
}
