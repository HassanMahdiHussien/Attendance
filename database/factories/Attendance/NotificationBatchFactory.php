<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\NotificationBatch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<NotificationBatch> */
class NotificationBatchFactory extends Factory
{
    protected $model = NotificationBatch::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'run_key' => 'manual:'.Str::uuid(), 'cut_off' => now(),
            'mode' => 'review', 'status' => 'draft',
        ];
    }
}
