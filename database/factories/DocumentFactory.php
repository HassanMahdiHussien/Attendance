<?php

namespace Database\Factories;

use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Document> */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'document_type' => 'defense_letter',
            'version' => 1,
            'drafted_by' => 'human',
        ];
    }
}
