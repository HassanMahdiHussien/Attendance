<?php

namespace Database\Factories;

use App\Models\AiGenerationLog;
use App\Models\Attachment;
use App\Models\Document;
use App\Models\SignaturePresenceSuggestion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SignaturePresenceSuggestion> */
class SignaturePresenceSuggestionFactory extends Factory
{
    protected $model = SignaturePresenceSuggestion::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'document_id' => Document::factory(),
            'case_id' => fn (array $attributes) => Document::findOrFail($attributes['document_id'])->case_id,
            'attachment_id' => fn (array $attributes) => Attachment::factory()->create([
                'attachable_type' => 'document', 'attachable_id' => $attributes['document_id'], 'kind' => 'signed_document_scan'])->id,
            'results' => [], 'ai_generation_log_id' => AiGenerationLog::factory()];
    }
}
