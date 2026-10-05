<?php

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentContent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DocumentContent> */
class DocumentContentFactory extends Factory
{
    protected $model = DocumentContent::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'document_id' => Document::factory(),
            'language_code' => 'ar',
            'body' => $this->faker->sentence(),
            'is_authoritative' => true,
        ];
    }
}
