<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Holiday;
use App\Models\Attendance\HolidayVersion;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HolidayVersion> */
class HolidayVersionFactory extends Factory
{
    protected $model = HolidayVersion::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(), 'holiday_id' => Holiday::factory(),
            'version' => 1, 'name_en' => 'Founders Day', 'ranges' => [['from' => '2026-10-20', 'to' => '2026-10-20']],
            'legal_entities' => [], 'branch_ids' => [], 'work_patterns' => [],
            'status' => 'approved', 'decided_by' => User::factory(), 'reason' => 'Approved announcement',
        ];
    }
}
