<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\FollowUpCase;
use App\Models\Employee;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FollowUpCase> */
class FollowUpCaseFactory extends Factory
{
    protected $model = FollowUpCase::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'employee_id' => Employee::factory(), 'work_date' => today()->subDay(),
            'state' => 'open', 'items' => [['type' => 'absence', 'minutes' => 480]],
            'routes' => [], 'request_ids' => [],
        ];
    }
}
