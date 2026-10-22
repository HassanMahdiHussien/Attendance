<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ManualPunch;
use App\Models\Employee;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ManualPunch> */
class ManualPunchFactory extends Factory
{
    protected $model = ManualPunch::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'employee_id' => Employee::factory(),
            'punched_at' => now()->subDay(),
            'role' => 'check_in',
            'reason' => 'Verified by HR',
            'created_by' => User::factory()->state([
                'company_id' => app(TenantContext::class)->companyId(), 'primary_role' => 'hr_staff',
            ]),
        ];
    }
}
