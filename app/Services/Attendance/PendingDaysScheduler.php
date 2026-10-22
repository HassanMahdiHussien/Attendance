<?php

namespace App\Services\Attendance;

use App\Jobs\Attendance\AssessDaysJob;
use App\Models\Attendance\Day;
use App\Models\Company;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

class PendingDaysScheduler
{
    public function dispatch(): int
    {
        $queued = 0;
        Company::query()->orderBy('id')->chunkById(50, function ($companies) use (&$queued): void {
            foreach ($companies as $company) {
                app(TenantContext::class)->runForCompany($company, function () use ($company, &$queued): void {
                    Day::query()->with('policy')->where('company_id', $company->id)
                        ->whereNull('superseded_by_run_id')->where('status', 'pending')
                        ->whereNotNull('required_leave_at')->where('required_leave_at', '<=', now('Asia/Baghdad'))
                        ->orderBy('id')->chunkById(500, function ($days) use ($company, &$queued): void {
                            $ready = [];
                            foreach ($days as $day) {
                                if ($day->policy === null || CarbonImmutable::parse($day->required_leave_at)->addMinutes($day->policy->close_grace_minutes) > now('Asia/Baghdad')) {
                                    continue;
                                }
                                $ready[$day->work_date->toDateString()][] = $day->employee_id;
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
