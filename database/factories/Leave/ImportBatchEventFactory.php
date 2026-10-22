<?php

namespace Database\Factories\Leave;

use App\Models\Leave\ImportBatch;
use App\Models\Leave\ImportBatchEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportBatchEvent> */
class ImportBatchEventFactory extends Factory
{
    protected $model = ImportBatchEvent::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'batch_id' => ImportBatch::factory(), 'sequence' => 1, 'state' => 'queued'];
    }
}
