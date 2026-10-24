<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Day;
use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\RestDayEntitlement;
use App\Models\Employee;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RestDayEntitlement> */
class RestDayEntitlementFactory extends Factory
{
    protected $model = RestDayEntitlement::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'employee_id' => Employee::factory(),
            'work_date' => today()->subDay(),
            'source_day_id' => Day::factory(),
            'quantity' => 0.5,
            'worked_minutes' => 240,
            'basis' => 'office_friday',
            'holiday' => false,
            'run_id' => ProcessingRun::factory()->state(['kind' => 'days']),
        ];
    }
}
