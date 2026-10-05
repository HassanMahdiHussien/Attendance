<?php

namespace Database\Factories;

use App\Models\DocumentTemplate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

class DocumentTemplateFactory extends Factory
{
    protected $model = DocumentTemplate::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(), 'document_type' => 'defense_letter',
            'language_code' => 'ar', 'version' => fake()->unique()->numberBetween(2, 999999),
            'blocks' => ['title' => 'Test'], 'options' => [],
        ];
    }
}
