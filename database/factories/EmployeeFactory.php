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
            'branch_id' => Branch::factory(),
            'company_id' => app(TenantContext::class)->companyId(),
            'name' => $this->faker->words(3, true),
            'department' => $this->faker->words(3, true),
            'position' => $this->faker->words(3, true),
            'hire_date' => now(),
            'gender' => 'male',
        ];
    }

    public function create($attributes = [], ?Model $parent = null)
    {
        return DB::transaction(fn () => parent::create($attributes, $parent));
    }
}
