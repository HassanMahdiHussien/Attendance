<?php

namespace Database\Factories;

use App\Models\CommitteeMandate;
use App\Models\CommitteePoolMember;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommitteePoolMember> */
class CommitteePoolMemberFactory extends Factory
{
    protected $model = CommitteePoolMember::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'mandate_id' => CommitteeMandate::factory(),
            'role_type' => 'hr',
            'person_name' => $this->faker->words(3, true),
        ];
    }
}
