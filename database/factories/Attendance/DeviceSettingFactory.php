<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DeviceSetting> */
class DeviceSettingFactory extends Factory
{
    protected $model = DeviceSetting::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'device_id' => Device::factory(),
            'effective_from' => today()->toDateString(),
            'role' => 'check_in',
            'branch_id' => Branch::factory(),
            'approved_by' => null,
        ];
    }
}
