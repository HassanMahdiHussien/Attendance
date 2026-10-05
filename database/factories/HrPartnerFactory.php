<?php

namespace Database\Factories;

use App\Models\HrPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<HrPartner> */
class HrPartnerFactory extends Factory
{
    protected $model = HrPartner::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'contact_email' => $this->faker->unique()->safeEmail(),
        ];
    }
}
