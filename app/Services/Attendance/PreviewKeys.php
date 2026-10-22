<?php

namespace App\Services\Attendance;

use Illuminate\Support\Facades\DB;

class PreviewKeys
{
    public function open(): void
    {
        // A connection-local table bounds PHP memory without persisting preview data.
        DB::statement('DROP TEMPORARY TABLE IF EXISTS attendance_preview_keys');
        DB::statement('CREATE TEMPORARY TABLE attendance_preview_keys (category VARCHAR(24) NOT NULL, fingerprint CHAR(64) NOT NULL, PRIMARY KEY (category, fingerprint)) ENGINE=InnoDB');
    }

    /** @return array<string, true> */
    public function add(string $category, array $fingerprints): array
    {
        $fingerprints = array_values(array_unique($fingerprints));
        if ($fingerprints === []) {
            return [];
        }

        $existing = DB::table('attendance_preview_keys')->where('category', $category)
            ->whereIn('fingerprint', $fingerprints)->pluck('fingerprint')->flip();
        $new = [];
        foreach ($fingerprints as $fingerprint) {
            if (! $existing->has($fingerprint)) {
                $new[$fingerprint] = true;
            }
        }
        DB::table('attendance_preview_keys')->insertOrIgnore(array_map(
            fn (string $fingerprint): array => ['category' => $category, 'fingerprint' => $fingerprint],
            array_keys($new),
        ));

        return $new;
    }

    public function close(): void
    {
        DB::statement('DROP TEMPORARY TABLE IF EXISTS attendance_preview_keys');
    }
}
