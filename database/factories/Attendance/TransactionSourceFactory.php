<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ImportBatch;
use App\Models\Attendance\Transaction;
use App\Models\Attendance\TransactionSource;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransactionSource> */
class TransactionSourceFactory extends Factory
{
    protected $model = TransactionSource::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'transaction_id' => Transaction::factory(),
            'batch_id' => ImportBatch::factory(),
            'row_number' => 3,
            'duplicate_in_file' => false,
            'person_code' => '12345678',
            'person_name' => 'Fake Person',
            'department_label' => 'Operations',
            'punch_date' => '2026-10-20',
            'punch_time' => '08:00',
            'punch_state' => 'Check In',
            'serial_number' => 'SER-12345678',
            'device_name' => 'Hilla_In_Face',
            'data_source' => 'Face',
        ];
    }
}
