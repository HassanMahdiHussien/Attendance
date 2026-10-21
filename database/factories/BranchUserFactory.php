<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BranchUser> */
class BranchUserFactory extends Factory
{
    protected $model = BranchUser::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'company_id' => app(TenantContext::class)->companyId(),
            'user_id' => User::factory()->state(fn (): array => [
                'company_id' => app(TenantContext::class)->companyId(),
                'primary_role' => UserRole::HrStaff,
            ]),
        ];
    }
}
