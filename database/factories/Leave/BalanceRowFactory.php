<?php

namespace Database\Factories\Leave;

use App\Models\Employee;
use App\Models\Leave\BalanceBatch;
use App\Models\Leave\BalanceRow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BalanceRow> */
class BalanceRowFactory extends Factory
{
    protected $model = BalanceRow::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'batch_id' => BalanceBatch::factory(), 'employee_id' => Employee::factory(),
            'row_number' => $this->faker->unique()->numberBetween(3, 1000000),
            'employee_code' => $this->faker->numerify('########'), 'outcome' => 'imported'];
    }
}
