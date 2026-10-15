<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Incident;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Incident> */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'branch_id' => Branch::factory(),
            'created_by_user_id' => User::factory()->state(fn (): array => [
                'company_id' => app(TenantContext::class)->companyId(), 'primary_role' => 'company_admin',
            ]),
        ];
    }
}
