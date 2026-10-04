<?php

namespace App\Services;

use App\Enums\SequenceKey;
use Illuminate\Support\Facades\DB;
use LogicException;

class SequenceService
{
    public function next(int $companyId, SequenceKey $key, int $year = 0): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('A sequence number requires a database transaction.');
        }

        $identity = ['company_id' => $companyId, 'sequence_key' => $key->value, 'year' => $year];
        DB::table('company_sequences')->insertOrIgnore($identity + [
            'next_value' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('company_sequences')->where($identity)->lockForUpdate()->first();
        $value = (int) $row->next_value;
        DB::table('company_sequences')->where('id', $row->id)->update([
            'next_value' => $value + 1, 'updated_at' => now(),
        ]);

        return $value;
    }
}
