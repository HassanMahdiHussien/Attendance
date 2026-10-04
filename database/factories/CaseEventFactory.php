<?php

namespace Database\Factories;

use App\Models\CaseEvent;
use App\Models\DisciplinaryCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaseEvent> */
class CaseEventFactory extends Factory
{
    protected $model = CaseEvent::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'event_type' => $this->faker->words(3, true),
        ];
    }
}
