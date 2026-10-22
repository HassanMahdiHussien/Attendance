<?php

namespace Database\Factories;

use App\Models\EmployeeImportMapping;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmployeeImportMapping> */
class EmployeeImportMappingFactory extends Factory
{
    protected $model = EmployeeImportMapping::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'header_signature' => hash('sha256', $this->faker->uuid()),
            'headers' => ['employee_code'],
            'mapping' => ['0' => 'employee_code'],
            'updated_by' => null,
        ];
    }
}
