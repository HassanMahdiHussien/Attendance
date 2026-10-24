<?php

namespace Database\Factories\Leave;

use App\Models\Leave\BalanceBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BalanceBatch> */
class BalanceBatchFactory extends Factory
{
    protected $model = BalanceBatch::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'file_path' => 'leave-balances/test.xlsx', 'file_name' => 'test.xlsx',
            'file_sha256' => hash('sha256', $this->faker->uuid()),
            'balance_date' => today('Asia/Baghdad'), 'balance_year' => (int) today('Asia/Baghdad')->year,
            'mapping' => []];
    }
}
