<?php

namespace Database\Factories\Leave;

use App\Models\Leave\ImportMapping;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportMapping> */
class ImportMappingFactory extends Factory
{
    protected $model = ImportMapping::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'header_signature' => hash('sha256', $this->faker->uuid()), 'headers' => [], 'mapping' => []];
    }
}
