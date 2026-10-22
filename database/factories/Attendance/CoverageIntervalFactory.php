<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\CoverageInterval;
use App\Models\Attendance\ImportBatch;
use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CoverageInterval> */
class CoverageIntervalFactory extends Factory
{
    protected $model = CoverageInterval::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'batch_id' => ImportBatch::factory(),
            'branch_id' => Branch::factory(),
            'coverage_from' => now()->subDay(),
            'coverage_to' => now(),
            'kind' => 'declared',
            'is_confirmed' => false,
        ];
    }
}
