<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Day;
use App\Models\Attendance\ProcessingRun;
use App\Models\Employee;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Day> */
class DayFactory extends Factory
{
    protected $model = Day::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'employee_id' => Employee::factory(),
            'work_date' => today()->subDay(),
            'run_id' => ProcessingRun::factory()->state(['kind' => 'days']),
            'status' => 'pending',
            'intervals' => [],
            'exits' => [],
            'inputs' => [],
        ];
    }
}
