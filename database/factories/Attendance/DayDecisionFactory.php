<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\DayDecision;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DayDecision> */
class DayDecisionFactory extends Factory
{
    protected $model = DayDecision::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'employee_id' => Employee::factory(),
            'work_date' => today()->subDay(),
            'decision' => 'confirm_absence',
            'reason' => 'Verified by HR',
            'created_by' => User::factory()->state([
                'company_id' => app(TenantContext::class)->companyId(), 'primary_role' => 'hr_staff',
            ]),
        ];
    }
}
