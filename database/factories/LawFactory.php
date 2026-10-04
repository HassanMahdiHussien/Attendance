<?php

namespace Database\Factories;

use App\Models\Law;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Law> */
class LawFactory extends Factory
{
    protected $model = Law::class;

    public function definition(): array
    {
        return [
            'company_id' => null,
            'kind' => 'statute',
            'name' => $this->faker->words(3, true),
            'source_file_path' => $this->faker->words(3, true),
        ];
    }
}
