<?php

namespace App\Services\Leave;

use App\Events\DaysAssessed;
use App\Jobs\Leave\ReconcileLeaveJob;

class ReconciliationTriggers
{
    public function daysAssessed(DaysAssessed $event): void
    {
        $this->dispatch($event->companyId, $event->employeeIds, 'days', $event->from, $event->to);
    }

    public function dispatch(int $companyId, array $employeeIds, string $trigger, ?string $from = null, ?string $to = null): void
    {
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return;
        }

        // One employee's entire allocation chain is replayed whenever any of its inputs changes.
        foreach (array_chunk($ids, 100) as $chunk) {
            ReconcileLeaveJob::dispatch($companyId, $chunk, $trigger, $from, $to)->afterCommit();
        }
    }
}
