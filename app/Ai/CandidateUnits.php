<?php

namespace App\Ai;

use App\LegalLibrary\TextNormalizer;
use App\Models\DisciplinaryCase;
use App\Models\LegalUnit;
use App\Support\Format\Violation;
use Illuminate\Support\Collection;

class CandidateUnits
{
    public function __construct(private TextNormalizer $normalizer) {}

    public function for(DisciplinaryCase $case, string $context, array $excludedIds = []): Collection
    {
        $words = $this->words(Violation::name($case, 'ar').' '.$case->description.' '.$context);
        $units = LegalUnit::query()->citableFor($case)->whereNotIn('legal_units.id', $excludedIds)->with(['law', 'topics'])->get();
        $ranked = $units->sortByDesc(function (LegalUnit $unit) use ($words): int {
            $topics = $unit->topics->contains('topic', 'discipline') ? 100000 : 0;

            return $topics + count(array_intersect($words, $this->words($unit->official_text)));
        });
        $max = max(0, (int) config('displanery.ai.max_candidates', 30));
        $statutes = $ranked->filter(fn (LegalUnit $unit) => $unit->law->kind->value === 'statute')->take(min(20, $max));
        $regulations = $ranked->filter(fn (LegalUnit $unit) => $unit->law->kind->value === 'internal_regulation')->take(min(10, max(0, $max - $statutes->count())));

        return $statutes->concat($regulations)->take($max)->values();
    }

    private function words(string $text): array
    {
        preg_match_all('/[\p{Arabic}\p{Latin}]{3,}/u', mb_strtolower($this->normalizer->normalize($text)), $matches);

        return array_values(array_diff(array_unique($matches[0]), ['الى', 'على', 'من', 'في', 'عن', 'كان', 'هذه', 'that', 'the', 'and']));
    }
}
