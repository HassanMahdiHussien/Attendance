<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ProcessingRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProcessingRun> */
class ProcessingRunFactory extends Factory
{
    protected $model = ProcessingRun::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'kind' => 'cleanup',
            'trigger_source' => 'manual',
            'person_codes' => [],
            'scope_from' => now()->subDay(),
            'scope_to' => now(),
            'policy_version' => 1,
            'status' => 'completed',
            'counts' => [],
            'started_at' => now(),
            'finished_at' => now(),
        ];
    }
}
