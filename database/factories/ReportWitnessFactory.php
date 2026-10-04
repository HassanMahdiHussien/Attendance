<?php

namespace Database\Factories;

use App\Models\ReportWitness;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportWitness> */
class ReportWitnessFactory extends Factory
{
    protected $model = ReportWitness::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'violation_report_id' => ViolationReport::factory(),
            'name' => $this->faker->words(3, true),
        ];
    }
}
