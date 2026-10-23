<?php

namespace Database\Factories\Leave;

use App\Models\Employee;
use App\Models\Leave\LeaveRequest;
use App\Models\Leave\ProcessingRun;
use App\Models\Leave\RequestDay;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RequestDay> */
class RequestDayFactory extends Factory
{
    protected $model = RequestDay::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'request_id' => LeaveRequest::factory(), 'employee_id' => Employee::factory(), 'run_id' => ProcessingRun::factory(), 'work_date' => today(), 'minutes' => 480];
    }
}
