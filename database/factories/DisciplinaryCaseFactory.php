<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DisciplinaryCase> */
class DisciplinaryCaseFactory extends Factory
{
    protected $model = DisciplinaryCase::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => app(TenantContext::class)->companyId(),
            'employee_id' => Employee::factory(),
            'case_number' => $this->faker->unique()->numberBetween(1, 1000000000),
            'jurisdiction_at_opening' => 'federal_iraq',
            'opened_by_user_id' => User::factory(),
            'violation_time' => now(),
            'violation_type' => $this->faker->words(3, true),
        ];
    }
}
