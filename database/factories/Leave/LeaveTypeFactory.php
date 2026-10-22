<?php

namespace Database\Factories\Leave;

use App\Models\Leave\LeaveType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LeaveType> */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    public function definition(): array
    {
        $name = 'Test '.$this->faker->unique()->uuid();

        return ['company_id' => app(TenantContext::class)->companyId(), 'name' => $name, 'normalized_key' => mb_strtolower($name).'|', 'covers' => false, 'consumes' => 'none', 'is_mapped' => false];
    }
}
