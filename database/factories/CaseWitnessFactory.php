<?php

namespace Database\Factories;

use App\Models\CaseWitness;
use App\Models\DisciplinaryCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaseWitness> */
class CaseWitnessFactory extends Factory
{
    protected $model = CaseWitness::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'name' => $this->faker->words(3, true),
        ];
    }
}
