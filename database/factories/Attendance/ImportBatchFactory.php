<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ImportBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportBatch> */
class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'uploaded_by' => null,
            'file_path' => 'attendance-imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'file_sha256' => hash('sha256', $this->faker->uuid()),
            'coverage_from' => now()->subDay(),
            'coverage_to' => now(),
            'coverage_branch_ids' => [],
            'mapping' => [],
            'summary' => [],
        ];
    }
}
