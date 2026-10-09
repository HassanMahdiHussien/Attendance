<?php

namespace Database\Factories;

use App\Models\DisciplinaryCase;
use App\Models\RegulationComparison;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegulationComparison> */
class RegulationComparisonFactory extends Factory
{
    protected $model = RegulationComparison::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'document_type' => 'defense_letter',
            'status' => 'ready',
            'origin' => 'manual',
        ];
    }
}
