<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Branch;
use App\Services\Attendance\DeviceRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AttendanceDevices extends AttendancePage
{
    protected static ?string $slug = 'attendance/devices';

    protected string $view = 'filament.admin.pages.attendance.devices';

    public ?int $selectedDevice = null;

    public string $action = 'approve';

    public string $role = 'check_in';

    public ?int $branchId = null;

    public string $effectiveFrom = '';

    public string $reason = '';

    public static function getNavigationLabel(): string
    {
        return __('messages.attendance_ui.devices_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function devices(): LengthAwarePaginator
    {
        return Device::query()->where('company_id', $this->company()->id)->orderBy('serial_number')->paginate(25);
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function details(Device $device): array
    {
        $setting = DeviceSetting::query()->where('device_id', $device->id)->whereDate('effective_from', '<=', today())
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        $times = DB::table('attendance_transactions')->where('company_id', $this->company()->id)
            ->where('device_id', $device->id)->selectRaw('MIN(punched_at) AS first_at, MAX(punched_at) AS last_at')->first();

        return ['setting' => $setting, 'first' => $times?->first_at, 'last' => $times?->last_at];
    }

    public function selectDevice(int $deviceId, string $action): void
    {
        $device = Device::query()->where('company_id', $this->company()->id)->findOrFail($deviceId);
        abort_unless(in_array($action, ['approve', 'change', 'retire'], true), 422);
        $setting = $this->details($device)['setting'];
        $this->selectedDevice = $device->id;
        $this->action = $action;
        $this->role = $setting?->role ?? $device->suggested_role ?? 'check_in';
        $this->branchId = $setting?->branch_id ?? $device->suggested_branch_id;
        $this->effectiveFrom = $action === 'approve' ? '' : today()->toDateString();
        $this->reason = '';
    }

    public function save(DeviceRegistry $registry): void
    {
        $device = Device::query()->where('company_id', $this->company()->id)->findOrFail($this->selectedDevice);
        $this->validate([
            'action' => 'required|in:approve,change,retire',
            'role' => 'required_unless:action,retire|in:check_in,check_out,restaurant',
            'branchId' => 'required_unless:action,retire|integer',
            'effectiveFrom' => 'required_unless:action,approve|date',
            'reason' => 'required_unless:action,approve|string|max:500',
        ]);
        match ($this->action) {
            'approve' => $registry->approve($device, $this->role, $this->branchId, auth()->user(), $this->effectiveFrom ?: null, $this->reason ?: null),
            'change' => $registry->change($device, $this->role, $this->branchId, auth()->user(), $this->effectiveFrom, $this->reason),
            'retire' => $registry->retire($device, auth()->user(), $this->effectiveFrom, $this->reason),
        };
        $this->selectedDevice = null;
    }
}
