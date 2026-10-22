<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\Person;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Person> */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'person_code' => $this->faker->unique()->numerify('########'),
            'kind' => 'unknown',
            'employee_id' => null,
            'department_label' => 'Operations',
            'classification_reason' => 'unmatched',
            'classified_at' => now(),
        ];
    }
}
