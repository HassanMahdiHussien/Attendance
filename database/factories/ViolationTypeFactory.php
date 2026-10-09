<?php

namespace Database\Factories;

use App\Models\ViolationType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ViolationType> */
class ViolationTypeFactory extends Factory
{
    protected $model = ViolationType::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'names' => ['ar' => 'مخالفة', 'en' => 'Violation'],
        ];
    }
}
