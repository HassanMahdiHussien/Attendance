<?php

namespace Database\Factories;

use App\Models\Citation;
use App\Models\Document;
use App\Models\Law;
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
            // A unit citable for a factory case (federal Iraq): an approved non-labour statute, so it is
            // not tied to the case's pinned labour-law version.
            'legal_unit_id' => function (): int {
                $law = Law::factory()->create(['kind' => 'statute', 'category' => 'social_security',
                    'approval_status' => 'approved']);
                $law->jurisdictions()->create(['jurisdiction' => 'federal_iraq']);

                return LegalUnit::factory()->create(['law_id' => $law->id])->id;
            },
            'claim_sentence' => $this->faker->sentence(),
        ];
    }
}
