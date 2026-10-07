<?php

namespace Database\Factories;

use App\Models\AiDraftNote;
use App\Models\AiGenerationLog;
use App\Models\Document;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiDraftNote> */
class AiDraftNoteFactory extends Factory
{
    protected $model = AiDraftNote::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'document_id' => Document::factory(),
            'case_id' => fn (array $attributes) => Document::findOrFail($attributes['document_id'])->case_id,
            'ai_generation_log_id' => fn () => AiGenerationLog::factory()->create([
                'company_id' => app(TenantContext::class)->companyId(),
            ])->id,
            'notes' => ['Check wording.'],
        ];
    }
}
