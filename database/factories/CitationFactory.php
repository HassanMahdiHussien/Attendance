<?php

namespace Database\Factories;

use App\Models\Citation;
use App\Models\Document;
use App\Models\LegalUnit;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Citation> */
class CitationFactory extends Factory
{
    protected $model = Citation::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'document_id' => Document::factory(),
            'legal_unit_id' => LegalUnit::factory(),
            'claim_sentence' => $this->faker->sentence(),
        ];
    }
}
