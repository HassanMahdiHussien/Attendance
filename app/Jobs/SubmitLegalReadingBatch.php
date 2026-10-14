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
use Throwable;

class SubmitLegalReadingBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesLegalImportContext;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public int $runId, public ?int $companyId)
    {
        $this->afterCommit();
    }

    public function handle(BatchPageReader $reader): void
    {
        $this->withinImportContext($this->companyId, function () use ($reader): void {
            $run = LegalImportRun::findOrFail($this->runId);
            $reader->submit($run);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->withinImportContext($this->companyId, function (): void {
            $run = LegalImportRun::find($this->runId);
            if ($run?->state === 'reading' && $run->batch_id === null) {
                app(BatchPageReader::class)->failSubmission($run, 'Batch submission failed — retry with AI.');
            }
        });
    }
}
