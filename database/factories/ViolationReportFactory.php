<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ViolationReport> */
class ViolationReportFactory extends Factory
{
    protected $model = ViolationReport::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => app(TenantContext::class)->companyId(),
            'source' => 'reporter',
            'occurred_at' => now(),
            'violation_type' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
        ];
    }
}
