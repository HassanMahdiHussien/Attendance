<?php

namespace App\Services\Documents;

use App\Exceptions\IssuanceBlocked;
use App\Jobs\CheckCitationRelevance;
use App\Models\Document;
use App\Models\LegalUnit;
use Illuminate\Support\Facades\DB;

class CitationSync
{
    public static function claims(string $body): array
    {
        $claims = [];
        foreach (preg_split('/(?<=[.!?؟۔؛\n])\s*/u', $body) as $sentence) {
            if (! preg_match_all('/\[\[cite:(\d+)\]\]/', $sentence, $matches)) {
                continue;
            }
            $sentence = trim($sentence);
            foreach ($matches[1] as $id) {
                $claims[] = ['legal_unit_id' => (int) $id, 'claim_sentence' => $sentence,
                    'claim_hash' => hash('sha256', $sentence)];
            }
        }

        return $claims;
    }

    public function sync(Document $document): void
    {
        if ($document->is_locked) {
            throw new IssuanceBlocked('لا يمكن تغيير استشهادات مستند مقفل.');
        }
        $arabic = $document->contents()->where('language_code', 'ar')->first()?->body ?? '';
        if ($document->document_type->value === 'recommendations') {
            $arabic .= "\n".($document->contents()->where('language_code', 'ar')->first()?->secondary_body ?? '');
        }
        $claims = collect(self::claims($arabic))->unique(fn (array $claim) => $claim['legal_unit_id'].':'.$claim['claim_hash']);
        $ids = $claims->pluck('legal_unit_id')->unique()->all();
        if ($ids && LegalUnit::query()->citableFor($document->disciplinaryCase)->whereKey($ids)->count() !== count($ids)) {
            throw new IssuanceBlocked('المادة المستشهد بها غير صالحة لهذه الحالة.');
        }
        $keys = $claims->map(fn (array $claim) => $claim['legal_unit_id'].':'.$claim['claim_hash'])->all();
        foreach ($document->citations()->get() as $citation) {
            if (! in_array($citation->legal_unit_id.':'.$citation->claim_hash, $keys, true)) {
                $citation->delete();
            }
        }
        foreach ($claims as $claim) {
            $document->citations()->firstOrCreate(
                ['legal_unit_id' => $claim['legal_unit_id'], 'claim_hash' => $claim['claim_hash']],
                ['company_id' => $document->company_id, 'claim_sentence' => $claim['claim_sentence']],
            );
        }
        $unchecked = $document->citations()->whereNull('relevance_check')->where('overridden', false)->exists();
        if ($unchecked) {
            DB::afterCommit(fn () => CheckCitationRelevance::dispatch($document->id, $document->company_id));
        }
    }
}
