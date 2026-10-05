<?php

namespace Database\Factories;

use App\Models\CompanyLetterhead;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class CompanyLetterheadFactory extends Factory
{
    protected $model = CompanyLetterhead::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'language_code' => 'ku', 'version' => fake()->unique()->numberBetween(2, 999999),
            'name' => fake()->company(), 'footer_text' => 'CONFIDENTIAL',
        ];
    }
}
