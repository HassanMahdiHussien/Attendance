<?php

namespace Database\Factories\Leave;

use App\Models\Employee;
use App\Models\Leave\Credit;
use App\Models\Leave\ProcessingRun;
use App\Models\Leave\RequestDay;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Credit> */
class CreditFactory extends Factory
{
    protected $model = Credit::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'employee_id' => Employee::factory(), 'source_day_id' => RequestDay::factory(), 'run_id' => ProcessingRun::factory(), 'minutes' => 60];
    }
}
