<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportDraftPruner
{
    public function prune(): int
    {
        $deleted = 0;
        DB::table('attendance_import_drafts')->where('created_at', '<', now()->subDays(2))
            ->orderBy('id')->chunkById(100, function ($drafts) use (&$deleted): void {
                foreach ($drafts as $draft) {
                    DB::transaction(function () use ($draft, &$deleted): void {
                        DB::table('companies')->where('id', $draft->company_id)->lockForUpdate()->first();
                        $current = DB::table('attendance_import_drafts')->where('id', $draft->id)->lockForUpdate()->first();
                        if ($current === null || CarbonImmutable::parse($current->created_at)->greaterThanOrEqualTo(now()->subDays(2))) {
                            return;
                        }
                        $batchUsesFile = DB::table('attendance_import_batches')
                            ->where('company_id', $current->company_id)->where('file_path', $current->file_path)->exists();
                        if (! $batchUsesFile) {
                            Storage::disk('local')->delete($current->file_path);
                        }
                        $deleted += DB::table('attendance_import_drafts')->where('id', $current->id)->delete();
                    });
                }
            });

        return $deleted;
    }
}
