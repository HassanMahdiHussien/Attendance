<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Branch> */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'name' => $this->faker->words(3, true),
            'jurisdiction' => 'federal_iraq',
        ];
    }
}
