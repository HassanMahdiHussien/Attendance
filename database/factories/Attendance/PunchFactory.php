<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\ProcessingRun;
use App\Models\Attendance\Punch;
use App\Models\Attendance\Transaction;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Punch> */
class PunchFactory extends Factory
{
    protected $model = Punch::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'transaction_id' => Transaction::factory(),
            'run_id' => ProcessingRun::factory(),
            'person_code' => $this->faker->unique()->numerify('########'),
            'employee_id' => null,
            'punched_at' => '2026-10-20 08:00:00',
            'role' => 'check_in',
            'status' => 'used',
            'group_key' => 'test-group',
            'reason_code' => null,
            'superseded_by_run_id' => null,
        ];
    }
}
