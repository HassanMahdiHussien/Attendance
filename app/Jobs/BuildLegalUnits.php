<?php

namespace App\Jobs;

use App\Jobs\Concerns\UsesLegalImportContext;
use App\LegalLibrary\UnitBuilder;
use App\Models\LegalImportRun;
use App\Services\AuditLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

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

    public function failed(?Throwable $exception): void
    {
        $run = LegalImportRun::withoutGlobalScopes()->find($this->runId);
        if ($run === null) {
            return;
        }
        $this->withinImportContext($run->company_id, function () use ($run): void {
            if ($run->state !== 'splitting') {
                return;
            }
            $run->update(['state' => 'failed', 'finished_at' => now(),
                'error' => 'Legal unit building failed. Review the source and retry.']);
            app(AuditLogger::class)->log('legal_import.failed', $run, ['reason' => 'unit_building_failed']);
        });
    }
}
