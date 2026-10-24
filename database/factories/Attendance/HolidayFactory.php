<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Holiday;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Holiday> */
class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'name' => 'Founders Day', 'status' => 'draft',
        ];
    }
}
