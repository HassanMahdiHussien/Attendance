<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Device;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Device> */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'serial_number' => 'SER-'.$this->faker->unique()->numerify('########'),
            'name' => 'Hilla_In_Face',
            'status' => 'pending',
            'suggested_role' => 'check_in',
            'suggested_branch_id' => null,
        ];
    }
}
