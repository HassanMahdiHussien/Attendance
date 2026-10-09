<?php

namespace Database\Factories;

use App\Models\IntakeQuestion;
use App\Models\ViolationReport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IntakeQuestion> */
class IntakeQuestionFactory extends Factory
{
    protected $model = IntakeQuestion::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(),
            'violation_report_id' => ViolationReport::factory(), 'source' => 'checklist',
            'aspect' => 'what', 'question' => 'What happened step by step?', 'sort_order' => 0];
    }
}
