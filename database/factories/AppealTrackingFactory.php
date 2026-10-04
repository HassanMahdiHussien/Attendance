<?php

namespace Database\Factories;

use App\Models\AppealTracking;
use App\Models\DisciplinaryCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AppealTracking> */
class AppealTrackingFactory extends Factory
{
    protected $model = AppealTracking::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'window_start' => now(),
            'window_end' => now(),
        ];
    }
}
