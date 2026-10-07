<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Models\ResponseTranscription;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ResponseTranscription> */
class ResponseTranscriptionFactory extends Factory
{
    protected $model = ResponseTranscription::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'attachment_id' => fn (array $attributes) => Attachment::factory()->create([
                'attachable_id' => $attributes['case_id'], 'kind' => 'employee_response_scan'])->id,
            'origin' => 'human', 'text' => 'Response text'];
    }
}
