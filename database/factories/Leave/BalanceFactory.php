<?php

namespace Database\Factories\Leave;

use App\Models\Employee;
use App\Models\Leave\Balance;
use App\Models\Leave\BalanceBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Balance> */
class BalanceFactory extends Factory
{
    protected $model = Balance::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'batch_id' => BalanceBatch::factory(), 'employee_id' => Employee::factory(),
            'balance_date' => today('Asia/Baghdad'), 'balance_year' => (int) today('Asia/Baghdad')->year,
            'remaining_days' => '12.5000', 'source_row' => 3];
    }
}
