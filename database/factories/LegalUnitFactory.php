<?php

namespace Database\Factories;

use App\Models\Law;
use App\Models\LegalUnit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LegalUnit> */
class LegalUnitFactory extends Factory
{
    protected $model = LegalUnit::class;

    public function definition(): array
    {
        return [
            'law_id' => Law::factory(),
            'version' => 1,
            'article_number' => $this->faker->words(3, true),
            'official_text' => $this->faker->sentence(),
            'status' => 'verified_human',
        ];
    }
}
