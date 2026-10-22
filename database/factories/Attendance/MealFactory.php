<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Meal;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Transaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Meal> */
class MealFactory extends Factory
{
    protected $model = Meal::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'person_code' => $this->faker->unique()->numerify('########'),
            'employee_id' => null,
            'department_label' => 'Operations',
            'meal_date' => '2026-10-20',
            'punch_count' => 1,
            'repeat_count' => 0,
            'first_transaction_id' => Transaction::factory(),
            'run_id' => ProcessingRun::factory(),
            'superseded_by_run_id' => null,
        ];
    }
}
