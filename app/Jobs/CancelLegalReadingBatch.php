<?php

namespace App\Jobs;

use App\Jobs\Concerns\UsesLegalImportContext;
use App\LegalLibrary\BatchPageReader;
use App\Models\LegalImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CancelLegalReadingBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesLegalImportContext;

    public int $timeout = 120;

    public function __construct(public int $runId, public ?int $companyId)
    {
        $this->afterCommit();
    }

    public function handle(BatchPageReader $reader): void
    {
        $this->withinImportContext($this->companyId, function () use ($reader): void {
            $run = LegalImportRun::findOrFail($this->runId);
            if ($run->state === 'cancelled' && $run->batch_id !== null && $run->batch_state !== 'canceling') {
                $reader->cancel($run);
            }
        });
    }
}
