<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\DisciplinaryCase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Attachment> */
class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'attachable_type' => 'case',
            'kind' => 'other',
            'path' => $this->faker->words(3, true),
            'original_name' => $this->faker->words(3, true),
            'mime_type' => $this->faker->words(3, true),
            'size_bytes' => 1,
            'attachable_id' => DisciplinaryCase::factory(),
        ];
    }
}
