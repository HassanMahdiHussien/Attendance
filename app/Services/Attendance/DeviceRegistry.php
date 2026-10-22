<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Device;
use App\Models\Branch;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

class DeviceRegistry
{
    public function __construct(private DataIssues $issues) {}

    public function register(Company $company, string $serial, string $name, string $state, ?int $batchId = null): Device
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $prefix = explode('_', $name, 2)[0];
        $matches = Branch::query()->where('company_id', $company->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($prefix)])->limit(2)->get();
        $branch = $matches->count() === 1 ? $matches->first() : null;
        $role = match (mb_strtolower($state)) {
            'check in' => 'check_in', 'check out' => 'check_out', '255' => 'restaurant', default => null,
        };
        if ($role === null) {
            $lower = mb_strtolower($name);
            $role = str_contains($lower, '_restaurant_') || str_contains($lower, '_restarrant_') ? 'restaurant'
                : (str_contains($lower, '_in_') ? 'check_in' : (str_contains($lower, '_out_') ? 'check_out' : null));
        }
        DB::table('attendance_devices')->insertOrIgnore([
            'company_id' => $company->id, 'serial_number' => $serial, 'name' => $name,
            'status' => 'pending', 'suggested_role' => $role, 'suggested_branch_id' => $branch?->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $device = Device::query()->where('company_id', $company->id)->where('serial_number', $serial)->lockForUpdate()->firstOrFail();
        if ($device->status === 'pending') {
            $this->issues->open($company, 'device_pending', 'device', $serial, $batchId);
        }

        return $device;
    }
}
