<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\CaseConflictClearance;
use App\Models\CommitteePoolMember;
use App\Models\DisciplinaryCase;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CaseConflictClearance> */
class CaseConflictClearanceFactory extends Factory
{
    protected $model = CaseConflictClearance::class;

    public function definition(): array
    {
        $companyId = app(TenantContext::class)->companyId();

        return [
            'company_id' => $companyId,
            'case_id' => DisciplinaryCase::factory(),
            'committee_pool_member_id' => CommitteePoolMember::factory(),
            'reason' => 'Identity checked by HR; different person with the same name.',
            'cleared_by' => User::factory()->state(['company_id' => $companyId, 'primary_role' => UserRole::HrStaff]),
        ];
    }
}
