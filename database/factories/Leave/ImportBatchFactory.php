<?php

namespace Database\Factories\Leave;

use App\Models\Leave\ImportBatch;
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
            'file_path' => 'leave-imports/test.xlsx',
            'file_name' => 'test.xlsx',
            'file_sha256' => hash('sha256', $this->faker->uuid()),
            'snapshot_at' => now(),
            'scope' => 'partial',
            'mapping' => [],
            'summary' => [],
        ];
    }
}
