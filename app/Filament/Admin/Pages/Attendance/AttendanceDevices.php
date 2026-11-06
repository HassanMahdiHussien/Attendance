<?php

namespace App\Filament\Admin\Pages\Attendance;

use App\Filament\Admin\Resources\Attendance\AttendancePage;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Branch;
use App\Services\Attendance\DeviceRegistry;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AttendanceDevices extends AttendancePage
{
    protected static ?string $slug = 'attendance/devices';

    protected string $view = 'filament.admin.pages.attendance.devices';

    public ?int $selectedDevice = null;

    public string $action = 'approve';

    public string $role = '';

    private ?LengthAwarePaginator $devicePage = null;

    private array $deviceDetails = [];

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
        if ($this->devicePage !== null) {
            return $this->devicePage;
        }
        $this->devicePage = Device::query()->where('company_id', $this->company()->id)
            ->with('suggestedBranch')->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderBy('serial_number')->paginate(25);
        $ids = $this->devicePage->getCollection()->pluck('id')->all();
        if ($ids !== []) {
            $companyId = $this->company()->id;
            // Original punches are never edited, so the newest punch id is enough to know when these counts change;
            // without the cache every visit scans all of the company's punches (about 2 s at 430k rows).
            $lastPunch = (int) DB::table('attendance_transactions')->where('company_id', $companyId)->max('id');
            // One entry per company and page, overwritten when punches change: the database cache never prunes old keys.
            $key = 'attendance:device-stats:'.$companyId.':'.hash('sha256', implode(',', $ids));
            $hit = Cache::get($key);
            if (! is_array($hit) || ($hit['last_punch'] ?? null) !== $lastPunch) {
                $hit = ['last_punch' => $lastPunch, 'rows' => DB::table('attendance_transactions')->where('company_id', $companyId)
                    ->whereIn('device_id', $ids)->select('device_id')
                    ->selectRaw('MIN(punched_at) AS first_at, MAX(punched_at) AS last_at, COUNT(*) AS punches, COUNT(DISTINCT person_code) AS people')
                    ->groupBy('device_id')->get()->map(fn (object $row): array => (array) $row)->all()];
                Cache::put($key, $hit, now()->addDays(7));
            }
            $stats = collect($hit['rows'])->map(fn (array $row): object => (object) $row)->keyBy('device_id');
            $settings = DeviceSetting::query()->with('branch')->whereIn('device_id', $ids)
                ->where('effective_from', '<=', today()->toDateString())->orderByDesc('effective_from')->orderByDesc('id')
                ->get()->unique('device_id')->keyBy('device_id');
            foreach ($ids as $id) {
                $stat = $stats->get($id);
                $this->deviceDetails[$id] = ['setting' => $settings->get($id), 'first' => $stat?->first_at,
                    'last' => $stat?->last_at, 'punches' => (int) ($stat?->punches ?? 0),
                    'people' => (int) ($stat?->people ?? 0)];
            }
        }

        return $this->devicePage;
    }

    public function branches(): array
    {
        return Branch::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function pendingCount(): int
    {
        return Device::query()->where('company_id', $this->company()->id)->where('status', 'pending')->count();
    }

    public function details(Device $device): array
    {
        if (isset($this->deviceDetails[$device->id])) {
            return $this->deviceDetails[$device->id];
        }
        $setting = DeviceSetting::query()->where('device_id', $device->id)->where('effective_from', '<=', today()->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        $times = DB::table('attendance_transactions')->where('company_id', $this->company()->id)
            ->where('device_id', $device->id)->selectRaw('MIN(punched_at) AS first_at, MAX(punched_at) AS last_at')->first();

        return ['setting' => $setting, 'first' => $times?->first_at, 'last' => $times?->last_at,
            'punches' => 0, 'people' => 0];
    }

    public function selectDevice(int $deviceId, string $action): void
    {
        $device = Device::query()->where('company_id', $this->company()->id)->findOrFail($deviceId);
        abort_unless(in_array($action, ['approve', 'change', 'retire'], true), 422);
        $setting = $this->details($device)['setting'];
        $this->selectedDevice = $device->id;
        $this->action = $action;
        $this->role = $setting?->role ?? $device->suggested_role ?? '';
        $this->branchId = $setting?->branch_id ?? $device->suggested_branch_id;
        $this->effectiveFrom = $action === 'approve' ? '' : today()->toDateString();
        $this->reason = '';
    }

    public function useFirstPunchDate(): void
    {
        $device = Device::query()->where('company_id', $this->company()->id)->findOrFail($this->selectedDevice);
        $first = $this->details($device)['first'];
        if ($first !== null) {
            $this->effectiveFrom = substr($first, 0, 10);
        }
    }

    public function save(DeviceRegistry $registry): void
    {
        $device = Device::query()->where('company_id', $this->company()->id)->findOrFail($this->selectedDevice);
        $this->validate([
            'action' => 'required|in:approve,change,retire',
            'role' => 'required_unless:action,retire|in:check_in,check_out,check_in_out,restaurant',
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
        $this->devicePage = null;
        $this->deviceDetails = [];
        Notification::make()->title(__('messages.attendance_ui.recalculating_days'))->success()->send();
    }
}
