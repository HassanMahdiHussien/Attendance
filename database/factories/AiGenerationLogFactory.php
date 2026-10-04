<?php

namespace Database\Factories;

use App\Models\AiGenerationLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiGenerationLog> */
class AiGenerationLogFactory extends Factory
{
    protected $model = AiGenerationLog::class;

    public function definition(): array
    {
        return [
            'touchpoint' => $this->faker->words(3, true),
            'model' => $this->faker->words(3, true),
            'input' => $this->faker->sentence(),
            'status' => 'succeeded',
        ];
    }
}
