<?php

namespace App\Services\Documents;

use App\Jobs\CheckCitationRelevance;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

class RelevanceCheck
{
    public function run(Document $draft): void
    {
        CheckCitationRelevance::dispatch($draft->id, $draft->company_id);
    }

    public function clear(Document $draft): void
    {
        DB::transaction(function () use ($draft): void {
            foreach ($draft->citations as $citation) {
                $citation->forceFill(['relevance_check' => null, 'relevance_reason' => null,
                    'overridden' => false, 'overridden_by' => null, 'overridden_at' => null, 'override_reason' => null])->save();
            }
            DB::afterCommit(fn () => $this->run($draft));
        });
    }
}
