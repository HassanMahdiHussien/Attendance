<?php

namespace Database\Factories;

use App\Models\ReportExport;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ReportExport> */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'requested_by' => User::factory()->state(['company_id' => app(TenantContext::class)->companyId(),
                'primary_role' => 'company_admin']),
            'report_type' => 'periodic',
            'parameters' => ['period' => 'custom', 'from' => '2026-01-01', 'to' => '2026-01-31'],
            'format' => 'pdf',
            'status' => 'queued',
        ];
    }
}
