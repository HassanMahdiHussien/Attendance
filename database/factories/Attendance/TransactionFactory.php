<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Device;
use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\Transaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $device = Device::factory()->create();

        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'person_code' => $this->faker->unique()->numerify('########'),
            'person_name' => 'Fake Person',
            'department_label' => 'Operations',
            'punch_date' => '2026-10-20',
            'punch_time' => '08:00',
            'punched_at' => '2026-10-20 08:00:00',
            'punch_state' => 'Check In',
            'serial_number' => $device->serial_number,
            'device_id' => $device->id,
            'device_name' => 'Hilla_In_Face',
            'data_source' => 'Face',
            'first_batch_id' => ImportBatch::factory(),
        ];
    }
}
