<?php

namespace App\Jobs\Attendance;

use App\Models\Company;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\LastUpdate;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class WarmResultCacheJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 420;

    public function __construct(public int $companyId)
    {
        $this->onQueue('summaries');
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(AttendanceSummary $summary, Overtime $overtime, LastUpdate $lastUpdate): void
    {
        $company = Company::findOrFail($this->companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($company, $summary, $overtime, $lastUpdate): void {
            $today = CarbonImmutable::now('Asia/Baghdad');
            $periods = [
                [$today->startOfMonth()->toDateString(), $today->toDateString()],
                [$today->subMonth()->startOfMonth()->toDateString(), $today->subMonth()->endOfMonth()->toDateString()],
                [$today->subDays(29)->toDateString(), $today->toDateString()],
            ];
            foreach (array_unique($periods, SORT_REGULAR) as [$from, $to]) {
                $filters = ReportFilters::fromArray($company, ['from' => $from, 'to' => $to]);
                foreach ([
                    fn () => $summary->totals($filters),
                    fn () => $overtime->totals($filters),
                    fn () => $lastUpdate->for($filters),
                ] as $warm) {
                    try {
                        $warm();
                    } catch (Throwable) {
                        // A later view can calculate the missing result directly.
                    }
                }
            }
        });
    }
}
