<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\DataIssue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DataIssue> */
class DataIssueFactory extends Factory
{
    protected $model = DataIssue::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'type' => 'unknown_person',
            'subject_type' => 'person',
            'subject_key' => $this->faker->unique()->numerify('########'),
            'open_key' => $this->faker->uuid(),
            'batch_id' => null,
            'status' => 'open',
            'details' => [],
        ];
    }
}
