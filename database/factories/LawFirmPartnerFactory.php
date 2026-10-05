<?php

namespace Database\Factories;

use App\Models\LawFirmPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LawFirmPartner> */
class LawFirmPartnerFactory extends Factory
{
    protected $model = LawFirmPartner::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'contact_email' => $this->faker->unique()->safeEmail(),
        ];
    }
}
