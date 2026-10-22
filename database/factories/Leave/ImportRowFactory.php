<?php

namespace Database\Factories\Leave;

use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportRow;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportRow> */
class ImportRowFactory extends Factory
{
    protected $model = ImportRow::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'batch_id' => ImportBatch::factory(), 'row_number' => 2, 'raw_values' => [], 'errors' => []];
    }
}
