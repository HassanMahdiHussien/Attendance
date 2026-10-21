<?php

namespace Database\Factories;

use App\Enums\SequenceKey;
use App\Models\CompanySequence;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompanySequence> */
class CompanySequenceFactory extends Factory
{
    protected $model = CompanySequence::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'sequence_key' => SequenceKey::EmployeeCode,
            'next_value' => 1,
        ];
    }
}
