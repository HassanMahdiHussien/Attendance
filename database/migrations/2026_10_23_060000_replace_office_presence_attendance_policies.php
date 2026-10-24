<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $marker = 'office_presence_540_from_policy:';
        $sources = DB::table('attendance_policies')->where(function ($query) use ($marker): void {
            $query->whereNull('reason')->orWhereRaw('LEFT(`reason`, ?) <> ?', [strlen($marker), $marker]);
        })->orderBy('company_id')->orderBy('version')->get();
        $versions = [];
        foreach (DB::table('attendance_policies')->select('company_id')->selectRaw('MAX(version) AS latest')
            ->groupBy('company_id')->get() as $row) {
            $versions[$row->company_id] = (int) $row->latest;
        }
        foreach ($sources as $source) {
            $reason = $marker.$source->id;
            if (DB::table('attendance_policies')->where('company_id', $source->company_id)->where('reason', $reason)->exists()) {
                continue;
            }
            $replacement = (array) $source;
            unset($replacement['id']);
            $replacement['version'] = ++$versions[$source->company_id];
            $replacement['min_presence_minutes'] = 540;
            $replacement['absence_minutes'] = 480;
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
