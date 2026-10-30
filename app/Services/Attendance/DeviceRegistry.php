<?php

namespace App\Services\Attendance;

use App\Enums\UserRole;
use App\Events\CoverageChanged;
use App\Jobs\Attendance\ProcessAttendanceJob;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\Device;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\Transaction;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Branches\BranchMatcher;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class DeviceRegistry
{
    public function __construct(private DataIssues $issues, private AuditLogger $audit) {}

    public function register(Company $company, string $serial, string $name, string $state, ?int $batchId = null): Device
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $prefix = explode('_', $name, 2)[0];
        $branch = app(BranchMatcher::class)->forCompany($company)->match($prefix);
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

    public function approve(Device $device, string $role, int $branchId, User $actor, ?string $effectiveFrom = null, ?string $reason = null): DeviceSetting
    {
        return $this->saveSetting($device, $role, $branchId, $actor, $effectiveFrom, $reason, 'pending');
    }

    public function change(Device $device, string $role, int $branchId, User $actor, string $effectiveFrom, ?string $reason = null): DeviceSetting
    {
        return $this->saveSetting($device, $role, $branchId, $actor, $effectiveFrom, $reason, 'approved');
    }

    public function retire(Device $device, User $actor, string $effectiveFrom, ?string $reason = null): ?DeviceSetting
    {
        $this->authorize($device, $actor);
        $date = $this->date($effectiveFrom);

        return DB::transaction(function () use ($device, $actor, $date, $reason): ?DeviceSetting {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($device->status === 'retired') {
                throw ValidationException::withMessages(['device' => __('messages.attendance_import.device_state_invalid')]);
            }
            $latest = DeviceSetting::query()->where('device_id', $device->id)
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            $setting = $latest === null ? null : DeviceSetting::create([
                'company_id' => $device->company_id, 'device_id' => $device->id,
                'effective_from' => $date, 'role' => 'retired', 'branch_id' => $latest->branch_id,
                'approved_by' => $actor->id, 'reason' => $reason,
            ]);
            $device->update(['status' => 'retired']);
            $issue = DataIssue::query()->where('open_key', 'device_pending|device|'.$device->serial_number)->first();
            if ($issue !== null) {
                $this->issues->dismiss($issue, $actor, __('messages.attendance_import.device_retired_resolution'));
            }
            $this->audit->log('attendance.device.retired', $device, [
                'device_id' => $device->id, 'effective_from' => $date, 'setting_id' => $setting?->id,
            ], $actor);
            if ($latest !== null) {
                $this->dispatchCoverage($device->company_id, [$latest->branch_id], $date);
            }
            $this->dispatchAffected($device, $date, $setting?->id);

            return $setting;
        });
    }

    private function saveSetting(Device $device, string $role, int $branchId, User $actor, ?string $effectiveFrom, ?string $reason, string $expectedStatus): DeviceSetting
    {
        $this->authorize($device, $actor);
        if (! in_array($role, ['check_in', 'check_out', 'restaurant'], true)) {
            throw ValidationException::withMessages(['role' => __('messages.attendance_import.device_role_invalid')]);
        }
        $company = Company::findOrFail($device->company_id);
        if (! Branch::query()->where('company_id', $company->id)->whereKey($branchId)->exists()) {
            throw ValidationException::withMessages(['branch' => __('messages.attendance_import.device_branch_invalid')]);
        }
        $date = $this->date($effectiveFrom ?? Transaction::query()->where('company_id', $company->id)
            ->where('device_id', $device->id)->min('punch_date') ?? today()->toDateString());

        return DB::transaction(function () use ($device, $role, $branchId, $actor, $date, $reason, $expectedStatus): DeviceSetting {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            if ($device->status !== $expectedStatus) {
                throw ValidationException::withMessages(['device' => __('messages.attendance_import.device_state_invalid')]);
            }
            $previous = DeviceSetting::query()->where('device_id', $device->id)
                ->orderByDesc('effective_from')->orderByDesc('id')->first();
            $setting = DeviceSetting::create([
                'company_id' => $device->company_id, 'device_id' => $device->id,
                'effective_from' => $date, 'role' => $role, 'branch_id' => $branchId,
                'approved_by' => $actor->id, 'reason' => $reason,
            ]);
            if ($expectedStatus === 'pending') {
                $device->update(['status' => 'approved']);
                $issue = DataIssue::query()->where('open_key', 'device_pending|device|'.$device->serial_number)->first();
                if ($issue !== null) {
                    $this->issues->resolve($issue, $actor, __('messages.attendance_import.device_approved_resolution'));
                }
            }
            $confirmedIntervals = $this->confirmDeclaredCoverage($device, $branchId, $date);
            $this->audit->log('attendance.device.'.($expectedStatus === 'pending' ? 'approved' : 'changed'), $device, [
                'device_id' => $device->id, 'before' => $previous === null ? null : ['role' => $previous->role, 'branch_id' => $previous->branch_id],
                'after' => ['role' => $role, 'branch_id' => $branchId], 'effective_from' => $date,
                'setting_id' => $setting->id, 'confirmed_coverage_interval_ids' => $confirmedIntervals,
            ], $actor);
            $this->dispatchCoverage($device->company_id, array_values(array_unique(array_filter([$branchId, $previous?->branch_id]))), $date);
            $this->dispatchAffected($device, $date, $setting->id);

            return $setting;
        });
    }

    private function confirmDeclaredCoverage(Device $device, int $branchId, string $date): array
    {
        $confirmed = [];
        $intervals = DB::table('attendance_coverage_intervals')->where('company_id', $device->company_id)
            ->where('branch_id', $branchId)->where('kind', 'declared')->where('is_confirmed', false)
            ->where('coverage_to', '>=', $date.' 00:00:00')->get();
        foreach ($intervals as $interval) {
            $confirmedFrom = max($interval->coverage_from, $date.' 00:00:00');
            $found = DB::table('attendance_transaction_sources as s')
                ->join('attendance_transactions as t', function ($join): void {
                    $join->on('t.id', '=', 's.transaction_id')->on('t.company_id', '=', 's.company_id');
                })
                ->where('s.company_id', $device->company_id)->where('s.batch_id', $interval->batch_id)
                ->where('t.device_id', $device->id)
                ->whereBetween('t.punched_at', [$confirmedFrom, $interval->coverage_to])->exists();
            if ($found) {
                if ($confirmedFrom === $interval->coverage_from) {
                    DB::table('attendance_coverage_intervals')->where('company_id', $device->company_id)
                        ->where('id', $interval->id)->update(['is_confirmed' => true]);
                    $confirmed[] = $interval->id;
                } else {
                    $confirmed[] = DB::table('attendance_coverage_intervals')->insertGetId([
                        'company_id' => $device->company_id, 'batch_id' => $interval->batch_id,
                        'branch_id' => $branchId, 'coverage_from' => $confirmedFrom,
                        'coverage_to' => $interval->coverage_to, 'kind' => 'declared',
                        'is_confirmed' => true, 'created_at' => now(),
                    ]);
                }
            }
        }

        return $confirmed;
    }

    private function dispatchCoverage(int $companyId, array $branchIds, string $from): void
    {
        $last = DB::table('attendance_coverage_intervals')->where('company_id', $companyId)
            ->whereIn('branch_id', $branchIds)->max('coverage_to');
        $to = max($from, today()->toDateString(), substr((string) $last, 0, 10));
        event(new CoverageChanged($companyId, $branchIds, $from, $to));
    }

    private function dispatchAffected(Device $device, string $date, ?int $settingId): void
    {
        $codes = Transaction::query()->where('company_id', $device->company_id)->where('device_id', $device->id)
            ->where('punched_at', '>=', $date.' 00:00:00')->distinct()->pluck('person_code')->all();
        if ($codes === []) {
            return;
        }
        $last = Transaction::query()->where('company_id', $device->company_id)->whereIn('person_code', $codes)->max('punched_at');
        $from = CarbonImmutable::parse($date)->subDay()->startOfDay()->toDateTimeString();
        $to = CarbonImmutable::parse($last)->endOfDay()->toDateTimeString();
        ProcessAttendanceJob::dispatch($device->company_id, $codes, $from, $to, 'device', null, $device->id, (string) ($settingId ?? 'retired'))->afterCommit();
    }

    private function date(string $date): string
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'Asia/Baghdad');

            if ($parsed !== false && $parsed->toDateString() === $date) {
                return $date;
            }
        } catch (Throwable) {
        }

        throw ValidationException::withMessages(['effective_from' => __('messages.attendance_import.device_date_invalid')]);
    }

    private function authorize(Device $device, User $actor): void
    {
        $company = Company::findOrFail($device->company_id);
        abort_unless(auth()->id() === $actor->id && $actor->is_active
            && in_array($actor->primary_role, [UserRole::HrStaff, UserRole::PlatformAdmin], true)
            && $actor->canAccessTenant($company) && app(TenantContext::class)->companyId() === $company->id, 403);
    }
}
