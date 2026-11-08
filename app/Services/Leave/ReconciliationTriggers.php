<?php

namespace App\Services\Leave;

use App\Events\DaysAssessed;
use App\Jobs\Leave\ReconcileLeaveJob;
use App\Services\Attendance\Reporting\MonthSummaries;

class ReconciliationTriggers
{
    public function daysAssessed(DaysAssessed $event): void
    {
        app(PendingReconciliation::class)->collect($event->companyId, $event->employeeIds, $event->from, $event->to);
    }

    public function dispatch(int $companyId, array $employeeIds, string $trigger, ?string $from = null, ?string $to = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return;
        }

        if ($trigger !== 'days') {
            app(MonthSummaries::class)->markDirty($companyId, $ids);
        }

        // One employee's entire allocation chain is replayed whenever any of its inputs changes.
        foreach (array_chunk($ids, 10) as $chunk) {
            ReconcileLeaveJob::dispatch($companyId, $chunk, $trigger, $from, $to)->afterCommit();
        }
    }
}
