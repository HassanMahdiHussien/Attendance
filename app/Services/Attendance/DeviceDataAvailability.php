<?php

namespace App\Services\Attendance;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

class DeviceDataAvailability
{
    private array $employees = [];

    private array $branches = [];

    public function load(Company $company): void
    {
        $this->employees = [];
        $this->branches = [];
        $settings = DB::table('attendance_device_settings as setting')
            ->join('attendance_devices as device', function ($join): void {
                $join->on('device.id', '=', 'setting.device_id')->on('device.company_id', '=', 'setting.company_id');
            })
            ->where('setting.company_id', $company->id)
            ->whereIn('device.status', ['approved', 'retired'])
            ->select('setting.device_id', 'setting.branch_id', 'setting.role', 'setting.effective_from')
            ->orderBy('setting.device_id')->orderByDesc('setting.effective_from')->orderByDesc('setting.id')
            ->get();
        $deviceId = null;
        $nextEffectiveFrom = null;
        foreach ($settings as $setting) {
            if ($deviceId !== $setting->device_id) {
                $deviceId = $setting->device_id;
                $nextEffectiveFrom = null;
            }
            $from = substr($setting->effective_from, 0, 10);
            if (($nextEffectiveFrom === null || $from < $nextEffectiveFrom)
                && in_array($setting->role, ['check_in', 'check_out', 'check_in_out'], true)) {
                $query = DB::table('attendance_transactions')
                    ->where('company_id', $company->id)->where('device_id', $deviceId)
                    ->where('punch_date', '>=', $from);
                if ($nextEffectiveFrom !== null) {
                    $query->where('punch_date', '<', $nextEffectiveFrom);
                }
                if ($setting->role !== 'check_in_out') {
                    $query->where('punch_state', '!=', '255');
                }
                $rows = $query->select('person_code')->selectRaw('MAX(punch_date) AS last_date')
                    ->groupBy('person_code')->get();
                foreach ($rows as $row) {
                    $this->employees[$row->person_code] = true;
                    $branchId = (int) $setting->branch_id;
                    $this->branches[$branchId] = max($this->branches[$branchId] ?? '', $row->last_date);
                }
            }
            $nextEffectiveFrom = $from;
        }
    }

    public function pendingReason(string $personCode, ?int $branchId, string $date): ?string
    {
        if (! isset($this->employees[$personCode])) {
            return 'no_device_for_employee';
        }
        if ($branchId !== null && ($this->branches[$branchId] ?? '') < $date) {
            return 'branch_data_ended';
        }

        return null;
    }
}
