<?php

namespace App\Services\Attendance;

use App\Jobs\Attendance\AssessDaysJob;
use App\Models\Attendance\Day;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

class PendingDaysScheduler
{
    public function __construct(private CoverageWindows $coverage) {}

    public function dispatch(): int
    {
        $queued = 0;
        Company::query()->orderBy('id')->chunkById(50, function ($companies) use (&$queued): void {
            foreach ($companies as $company) {
                app(TenantContext::class)->runForCompany($company, function () use ($company, &$queued): void {
                    $pending = Day::query()->with('policy')->where('company_id', $company->id)
                        ->whereNull('superseded_by_run_id')->where('status', 'pending')
                        ->whereNotNull('required_leave_at')->where('required_leave_at', '<=', now('Asia/Baghdad'));
                    $from = (clone $pending)->min('work_date');
                    if ($from === null) {
                        return;
                    }
                    $this->coverage->load($company, substr((string) $from, 0, 10),
                        CarbonImmutable::parse((clone $pending)->max('work_date'))->addDays(3)->toDateString());
                    $pending->orderBy('id')->chunkById(500, function ($days) use ($company, &$queued): void {
                        $ready = [];
                        foreach ($days as $day) {
                            if ($day->policy === null) {
                                continue;
                            }
                            $until = CarbonImmutable::parse($day->required_leave_at)->addMinutes($day->policy->close_grace_minutes);
                            $date = $day->work_date->toDateString();
                            $start = $date.' '.($day->shift_start ?? '00:00:00');
                            // Without coverage a day stays pending however long it waits; CoverageChanged reassesses it when that changes.
                            if ($until > now('Asia/Baghdad') || ! $this->coverage->coversWindow((int) $day->branch_id, $start, $until->format('Y-m-d H:i:s'))) {
                                continue;
                            }
                            $ready[$date][] = $day->employee_id;
                        }
                        foreach ($ready as $date => $employeeIds) {
                            AssessDaysJob::dispatch($company->id, [
                                'employee_ids' => array_values(array_unique($employeeIds)),
                                'from' => $date, 'to' => $date,
                            ], 'pending')->afterCommit();
                            $queued++;
                        }
                    });
                });
            }
        });

        return $queued;
    }
}
