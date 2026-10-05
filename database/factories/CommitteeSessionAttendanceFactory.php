<?php

namespace Database\Factories;

use App\Models\CommitteePoolMember;
use App\Models\CommitteeSessionAttendance;
use App\Models\DisciplinaryCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommitteeSessionAttendance> */
class CommitteeSessionAttendanceFactory extends Factory
{
    protected $model = CommitteeSessionAttendance::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'committee_pool_member_id' => CommitteePoolMember::factory(),
            'attended_at' => now(),
        ];
    }
}
