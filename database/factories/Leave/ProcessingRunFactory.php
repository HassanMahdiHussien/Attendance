<?php

namespace Database\Factories\Leave;

use App\Models\Leave\ProcessingRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProcessingRun> */
class ProcessingRunFactory extends Factory
{
    protected $model = ProcessingRun::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'trigger_source' => 'test', 'scope' => [], 'status' => 'completed'];
    }
}
