<?php

namespace Database\Factories\Leave;

use App\Models\Leave\ImportBatch;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\RequestVersion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequestVersion> */
class RequestVersionFactory extends Factory
{
    protected $model = RequestVersion::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'request_id' => LeaveRequest::factory(), 'batch_id' => ImportBatch::factory(), 'row_number' => 2, 'snapshot_at' => now(), 'values' => [], 'raw_values' => []];
    }
}
