<?php

namespace Database\Factories;

use App\Models\Law;
use App\Models\LawJurisdiction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LawJurisdiction> */
class LawJurisdictionFactory extends Factory
{
    protected $model = LawJurisdiction::class;

    public function definition(): array
    {
        return [
            'law_id' => Law::factory(),
            'jurisdiction' => 'federal_iraq',
        ];
    }
}
