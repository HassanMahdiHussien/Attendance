<?php

namespace Database\Factories\Attendance;

use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendanceExportEvent;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AttendanceExportEvent> */
class AttendanceExportEventFactory extends Factory
{
    protected $model = AttendanceExportEvent::class;

    public function definition(): array
    {
        return [
            'company_id' => app(TenantContext::class)->companyId(),
            'export_id' => AttendanceExport::factory(),
            'sequence' => 1,
            'state' => 'queued',
        ];
    }
}
