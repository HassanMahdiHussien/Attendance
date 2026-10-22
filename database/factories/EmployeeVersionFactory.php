<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeVersion> */
class EmployeeVersionFactory extends Factory
{
    protected $model = EmployeeVersion::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'employee_id' => Employee::factory(),
            'effective_from' => today()->toDateString(),
            'changes' => ['department' => $this->faker->word()],
            'previous' => ['department' => null],
            'source' => 'manual',
            'changed_by' => null,
        ];
    }
}
