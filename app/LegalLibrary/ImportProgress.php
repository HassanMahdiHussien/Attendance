<?php

namespace App\LegalLibrary;

use App\Jobs\BuildLegalUnits;
use App\Models\LegalImportRun;
use Illuminate\Support\Facades\DB;

class ImportProgress
{
    public function evaluate(LegalImportRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = LegalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->state !== 'reading') {
                return;
            }
            $pages = $locked->pages()->get();
            if ($pages->isEmpty() || $pages->contains(fn ($page) => $page->text_status === 'pending'
                || $page->ai_status === 'pending')) {
                return;
            }
            if ($pages->contains(fn ($page) => $page->ai_status === 'unavailable')) {
                $locked->update(['state' => 'ai_pending']);

                return;
            }
            $locked->update(['state' => 'splitting']);
            BuildLegalUnits::dispatch($locked->id)->afterCommit();
        });
    }
}
