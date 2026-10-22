<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\ImportBatchEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportBatchEvent> */
class ImportBatchEventFactory extends Factory
{
    protected $model = ImportBatchEvent::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'batch_id' => ImportBatch::factory(),
            'sequence' => 1,
            'state' => 'queued',
            'counts' => [],
        ];
    }
}
