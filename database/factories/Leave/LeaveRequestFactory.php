<?php

namespace Database\Factories\Leave;

use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveRequest> */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'source' => 'company_report',
            'external_id' => $this->faker->unique()->uuid(),
            'employee_id' => Employee::factory(),
            'owner_code' => '12345678',
            'status' => 'pending',
            'raw_status' => 'Manager Approval',
            'start_date' => today(),
            'end_date' => today(),
            'duration_days' => '1.0',
            'flags' => [],
        ];
    }
}
