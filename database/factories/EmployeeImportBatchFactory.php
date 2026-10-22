<?php

namespace Database\Factories;

use App\Models\EmployeeImportBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeImportBatch> */
class EmployeeImportBatchFactory extends Factory
{
    protected $model = EmployeeImportBatch::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'uploaded_by' => null,
            'mode' => 'partial',
            'effective_date' => today()->toDateString(),
            'file_path' => 'employee-imports/test.csv',
            'file_name' => 'test.csv',
            'file_sha256' => hash('sha256', $this->faker->uuid()),
            'mapping' => [],
            'counts' => [],
            'summary' => [],
        ];
    }
}
