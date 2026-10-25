<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ApproverMapping;
use App\Models\Employee;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ApproverMapping> */
class ApproverMappingFactory extends Factory
{
    protected $model = ApproverMapping::class;

    public function definition(): array
    {
        $name = fake()->unique()->name();

        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'approver_key' => mb_strtolower(trim($name)), 'approver_text' => $name,
            'employee_id' => Employee::factory(), 'confirmed_at' => now(),
        ];
    }
}
