<?php

namespace Database\Factories;

use App\Models\ReportAssistantSession;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportAssistantSession> */
class ReportAssistantSessionFactory extends Factory
{
    protected $model = ReportAssistantSession::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'user_id' => User::factory()->state(['company_id' => app(TenantContext::class)->companyId(), 'primary_role' => 'reporter']),
            'status' => 'ready', 'language' => 'ar',
            'transcript' => [], 'facts' => [],
        ];
    }
}
