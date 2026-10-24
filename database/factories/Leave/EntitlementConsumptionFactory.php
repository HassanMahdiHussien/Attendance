<?php

namespace Database\Factories\Leave;

use App\Models\Attendance\RestDayEntitlement;
use App\Models\Employee;
use App\Models\Leave\EntitlementConsumption;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\ProcessingRun;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EntitlementConsumption> */
class EntitlementConsumptionFactory extends Factory
{
    protected $model = EntitlementConsumption::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'employee_id' => Employee::factory(),
            'request_id' => LeaveRequest::factory(),
            'request_day_id' => null,
            'entitlement_id' => RestDayEntitlement::factory(),
            'quantity' => 0.5,
            'explanation' => ['code' => 'rest_day_entitlement_used'],
            'run_id' => ProcessingRun::factory(),
        ];
    }
}
