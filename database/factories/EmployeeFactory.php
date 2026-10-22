<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Employee;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Employee> */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'branch_id' => fn (array $attributes): int => Branch::factory()->create(['company_id' => $attributes['company_id']])->id,
            'name' => $this->faker->words(3, true),
            'department' => $this->faker->words(3, true),
            'position' => $this->faker->words(3, true),
            'hire_date' => now(),
            'gender' => 'male',
            'work_pattern' => null,
            'rest_days' => null,
            'exit_status' => 'none',
        ];
    }

    public function create($attributes = [], ?Model $parent = null)
    {
        return DB::transaction(fn () => parent::create($attributes, $parent));
    }
}
