<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $marker = 'office_rest_hour_480_from_policy:';
        $latestByDate = DB::table('attendance_policies')->orderBy('company_id')->orderBy('effective_from')
            ->orderByDesc('version')->get()->unique(fn ($row): string => $row->company_id.'|'.$row->effective_from);
        $versions = DB::table('attendance_policies')->select('company_id')->selectRaw('MAX(version) AS latest')
            ->groupBy('company_id')->pluck('latest', 'company_id')->map(fn ($version): int => (int) $version)->all();

        foreach ($latestByDate as $source) {
            if ((int) $source->min_presence_minutes !== 540 || (int) $source->workday_minutes !== 540) {
                continue;
            }
            $reason = $marker.$source->id;
            if (DB::table('attendance_policies')->where('company_id', $source->company_id)->where('reason', $reason)->exists()) {
                continue;
            }
            $replacement = (array) $source;
            unset($replacement['id']);
            $replacement['version'] = ++$versions[$source->company_id];
            $replacement['min_presence_minutes'] = 480;
            $replacement['created_by'] = null;
            $replacement['reason'] = $reason;
            $replacement['created_at'] = now();
            DB::table('attendance_policies')->insert($replacement);
        }
    }

    public function down(): void
    {
        // Policy history is append-only; rolling back code must not erase the effective versions.
    }
};
