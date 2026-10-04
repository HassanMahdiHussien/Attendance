<?php

namespace Database\Factories;

use App\Models\DisciplinaryCase;
use App\Models\Signature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Signature> */
class SignatureFactory extends Factory
{
    protected $model = Signature::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'case_id' => DisciplinaryCase::factory(),
            'purpose' => 'defense_letter_receipt',
            'signer_name' => $this->faker->words(3, true),
            'signer_role' => $this->faker->words(3, true),
        ];
    }
}
