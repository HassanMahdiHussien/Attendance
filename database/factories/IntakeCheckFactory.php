<?php

namespace Database\Factories;

use App\Models\IntakeCheck;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IntakeCheck> */
class IntakeCheckFactory extends Factory
{
    protected $model = IntakeCheck::class;

    public function definition(): array
    {
        return ['company_id' => app(TenantContext::class)->companyId(), 'user_id' => User::factory()->state(['company_id' => app(TenantContext::class)->companyId(), 'primary_role' => 'company_admin']),
            'status' => 'checklist', 'language' => 'en', 'input_hash' => hash('sha256', 'test'),
            'request_token' => (string) Str::uuid(), 'input' => ['description' => 'Test incident', 'violation_type' => '', 'place' => '', 'time' => ''],
            'result' => ['complete' => false, 'questions' => []]];
    }
}
