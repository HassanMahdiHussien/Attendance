<?php

namespace App\Jobs;

use App\Jobs\Concerns\UsesLegalImportContext;
use App\LegalLibrary\UnitBuilder;
use App\Models\LegalImportRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BuildLegalUnits implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, UsesLegalImportContext;

    public int $timeout = 600;

    public function __construct(public int $runId)
    {
        $this->afterCommit();
    }

    public function handle(UnitBuilder $builder): void
    {
        $run = LegalImportRun::withoutGlobalScopes()->findOrFail($this->runId);
        $this->withinImportContext($run->company_id, fn () => $builder->build($run));
    }
}
