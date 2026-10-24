<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $marker = 'overtime_defaults_from_policy:';
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
            $replacement['overtime_min_minutes'] = 30;
            $replacement['overtime_step_minutes'] = 15;
            $replacement['rest_day_half_minutes'] = 240;
            $replacement['rest_day_full_minutes'] = 480;
            $replacement['compensatory_rest_days'] = json_encode(['office' => [5], 'shift' => [5], 'security' => 'individual']);
            $replacement['created_by'] = null;
            $replacement['reason'] = $reason;
            $replacement['created_at'] = now();
            DB::table('attendance_policies')->insert($replacement);
        }
    }

    public function down(): void
    {
        // Historical policies remain immutable when application code rolls back.
    }
};
