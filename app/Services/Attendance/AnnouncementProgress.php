<?php

namespace App\Services\Attendance;

use App\Models\Attendance\Day;
use App\Models\Attendance\Holiday;
use App\Models\Attendance\ProcessingRun;
use Illuminate\Support\Facades\Bus;

class AnnouncementProgress
{
    public function forHoliday(Holiday $holiday): ?array
    {
        $batch = $holiday->reprocess_batch_id ? Bus::findBatch($holiday->reprocess_batch_id) : null;
        if ($batch === null) {
            return null;
        }
        $people = null;
        if ($batch->finished()) {
            $runs = ProcessingRun::query()->where('company_id', $holiday->company_id)
                ->where('reprocess_batch_id', $batch->id)->pluck('id');
            $people = Day::query()->where('company_id', $holiday->company_id)
                ->whereIn('run_id', $runs)->distinct()->count('employee_id');
        }

        return ['done' => $batch->processedJobs(), 'total' => $batch->totalJobs,
            'finished' => $batch->finished(), 'failed' => $batch->failedJobs, 'people' => $people];
    }
}
