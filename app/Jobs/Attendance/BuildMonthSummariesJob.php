<?php

namespace App\Jobs\Attendance;

use App\Jobs\Leave\ReconcileLeaveJob;
use App\Models\Attendance\MonthSummary;
use App\Models\Attendance\PendingLeaveReconciliation;
use App\Models\Company;
use App\Services\Attendance\Reporting\MonthSummaries;
use App\Services\Leave\PendingReconciliation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class BuildMonthSummariesJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(public int $companyId)
    {
        $this->onQueue('summaries');
    }

    public function uniqueId(): string
    {
        return (string) $this->companyId;
    }

    public function handle(MonthSummaries $summaries): void
    {
        $company = Company::findOrFail($this->companyId);
        app(TenantContext::class)->runForCompany($company, function () use ($summaries, $company): void {
            if (PendingReconciliation::defers() && (PendingLeaveReconciliation::query()->where('company_id', $this->companyId)->exists()
                || app(PendingReconciliation::class)->hasUpstreamWork($company))) {
                static::dispatch($this->companyId)->delay(now()->addSeconds(10))->afterCommit();
                // Re-arm leave matching in case its job failed; the unique lock keeps this to one job.
                ReconcileLeaveJob::dispatch($this->companyId, [], 'days')->delay(now()->addSeconds(10))->afterCommit();

                return;
            }
            $rows = MonthSummary::query()->where('company_id', $this->companyId)->where('is_dirty', true)
                ->orderBy('month')->orderBy('id')->limit(10)->get();
            foreach ($rows as $row) {
                $summaries->build($row);
            }
            if (MonthSummary::query()->where('company_id', $this->companyId)->where('is_dirty', true)->exists()) {
                $summaries->queue($this->companyId);
            } else {
                try {
                    WarmResultCacheJob::dispatch($this->companyId)->afterCommit();
                } catch (Throwable) {
                    // A cache warmup cannot invalidate a completed month build.
                }
            }
        });
    }
}
