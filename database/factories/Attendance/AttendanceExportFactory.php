<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\AttendanceExport;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceExport> */
class AttendanceExportFactory extends Factory
{
    protected $model = AttendanceExport::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'requested_by' => null,
            'kind' => 'employee_statement_xlsx',
            'filters' => ['from' => '2026-10-01', 'to' => '2026-10-31'],
            'locale' => 'en',
            'file_name' => 'attendance-test.xlsx',
        ];
    }
}
