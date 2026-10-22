<?php

namespace Database\Factories\Leave;

use App\Models\Attendance\Day;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\ProcessingRun;
use App\Models\Leave\RequestDay;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Allocation> */
class AllocationFactory extends Factory
{
    protected $model = Allocation::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'employee_id' => Employee::factory(), 'source_request_day_id' => RequestDay::factory(), 'target_day_id' => Day::factory(), 'target_kind' => 'late', 'minutes' => 15, 'explanation' => [], 'run_id' => ProcessingRun::factory()];
    }
}
