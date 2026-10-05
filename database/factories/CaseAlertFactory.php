<?php

namespace Database\Factories;

use App\Models\CaseAlert;
use App\Models\DisciplinaryCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaseAlert> */
class CaseAlertFactory extends Factory
{
    protected $model = CaseAlert::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'alert_type' => 'procedure_approaching',
            'stage' => $this->faker->unique()->date(),
            'status' => 'pending',
            'attempts' => 0,
        ];
    }
}
