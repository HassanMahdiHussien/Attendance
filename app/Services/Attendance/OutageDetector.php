<?php

namespace App\Services\Attendance;

use App\Events\CoverageChanged;
use App\Models\Attendance\DataIssue;
use App\Models\Attendance\DeviceSetting;
use App\Models\Attendance\Transaction;
use App\Models\Branch;
use App\Models\Company;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

class OutageDetector
{
    private array $settings = [];

    private ?int $preparedCompany = null;

    public function __construct(private DataIssues $issues, private AuditLogger $audit) {}

    public function prepare(Company $company, string $to): void
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        $this->preparedCompany = $company->id;
        $this->settings = DeviceSetting::query()->where('company_id', $company->id)
            ->whereDate('effective_from', '<=', $to)
            ->orderByDesc('effective_from')->orderByDesc('id')->get()->groupBy('device_id')->all();
    }

    public function run(Company $company, int $branchId, string $date, string $until, bool $covered, bool $expected, ?string $start = null): bool
    {
        abort_unless(app(TenantContext::class)->companyId() === $company->id, 403);
        // Serialize the branch-date decision even when concurrent assessments lock different employees.
        Branch::query()->where('company_id', $company->id)->whereKey($branchId)->lockForUpdate()->firstOrFail();
        $subject = 'branch:'.$branchId.':'.$date;
        $existing = DataIssue::query()->where('company_id', $company->id)
            ->where('type', 'device_outage')->where('subject_type', 'branch_date')
            ->where('subject_key', $subject)->first();
        if ($existing !== null && $existing->status !== 'open') {
            return false;
        }
        $start ??= $date.' 00:00:00';
        $sameWindow = $existing === null || ! isset($existing->details['window_from'])
            || ($existing->details['window_from'] === $start && $existing->details['window_to'] === $until);
        if (! $covered || ! $expected) {
            if ($sameWindow) {
                $this->clear($existing, $company, $branchId, $date, $subject);
            }

            return false;
        }

        if ($this->preparedCompany !== $company->id) {
            $this->prepare($company, substr($until, 0, 10));
        }
        $startDate = substr($start, 0, 10);
        $approvedAtStart = false;
        foreach ($this->settings as $settings) {
            $setting = $settings->first(fn (DeviceSetting $candidate): bool => $candidate->effective_from->toDateString() <= $startDate);
            if ($setting !== null && (int) $setting->branch_id === $branchId && $setting->role === 'check_in') {
                $approvedAtStart = true;

                break;
            }
        }
        if (! $approvedAtStart) {
            if ($sameWindow) {
                $this->clear($existing, $company, $branchId, $date, $subject);
            }

            return false;
        }
        $hasApprovedDevice = false;
        for ($day = CarbonImmutable::parse(substr($start, 0, 10)); $day->toDateString() <= substr($until, 0, 10); $day = $day->addDay()) {
            $localDate = $day->toDateString();
            $windowFrom = max($start, $localDate.' 00:00:00');
            $windowTo = min($until, $localDate.' 23:59:59');
            $deviceIds = [];
            foreach ($this->settings as $deviceId => $settings) {
                $setting = $settings->first(fn (DeviceSetting $candidate): bool => $candidate->effective_from->toDateString() <= $localDate);
                if ($setting === null || (int) $setting->branch_id !== $branchId || $setting->role !== 'check_in') {
                    continue;
                }
                $hasApprovedDevice = true;
                $deviceIds[] = $deviceId;
            }
            if ($deviceIds !== [] && Transaction::query()->where('company_id', $company->id)
                ->whereIn('device_id', $deviceIds)->whereBetween('punched_at', [$windowFrom, $windowTo])->exists()) {
                if ($sameWindow) {
                    $this->clear($existing, $company, $branchId, $date, $subject);
                }

                return false;
            }
        }
        if (! $hasApprovedDevice) {
            if ($sameWindow) {
                $this->clear($existing, $company, $branchId, $date, $subject);
            }

            return false;
        }
        if ($existing === null) {
            $this->issues->open($company, 'device_outage', 'branch_date', $subject, details: [
                'branch_id' => $branchId, 'work_date' => $date, 'window_from' => $start, 'window_to' => $until,
            ]);
        }

        return true;
    }

    private function clear(?DataIssue $existing, Company $company, int $branchId, string $date, string $subject): void
    {
        if ($existing === null) {
            return;
        }
        $existing->update(['status' => 'resolved', 'open_key' => null, 'note' => __('messages.office_attendance.outage_cleared'), 'resolved_at' => now()]);
        $this->audit->log('attendance.issue.resolved', $existing, [
            'type' => 'device_outage', 'subject_key' => $subject,
            'before' => 'open', 'after' => 'resolved',
        ]);
        event(new CoverageChanged($company->id, [$branchId], $date, $date));
    }
}
