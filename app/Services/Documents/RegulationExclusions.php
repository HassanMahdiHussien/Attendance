<?php

namespace App\Services\Documents;

use App\Ai\RegulationComparisons;
use App\Enums\DocumentType;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\LegalUnit;
use App\Models\RegulationComparison;

class RegulationExclusions
{
    public function for(Document $document): array
    {
        return $this->forComparisons($document->disciplinaryCase,
            $document->regulationComparison()->first(), $document->supplementaryComparison()->first());
    }

    public function forComparisons(DisciplinaryCase $case, ?RegulationComparison $primary, ?RegulationComparison $supplement = null): array
    {
        $all = $this->regulationIds($case);
        $comparisons = [$primary, $supplement];
        if (collect($comparisons)->contains(fn (?RegulationComparison $comparison) => $comparison?->status === 'skipped')) {
            return $all;
        }

        $allowed = [];
        $conflicting = [];
        foreach ($comparisons as $comparison) {
            if ($comparison?->status !== 'confirmed') {
                continue;
            }
            $allowed = array_merge($allowed, array_map('intval', $comparison->pairs()
                ->whereIn('verdict', ['consistent', 'more_favourable'])->pluck('regulation_unit_id')->all()));
            $conflicting = array_merge($conflicting, $this->forComparison($comparison, $case));
        }

        return array_values(array_diff($all, array_diff($allowed, $conflicting)));
    }

    public function refusalKey(Document $document, int $unitId): string
    {
        foreach ([$document->regulationComparison()->first(), $document->supplementaryComparison()->first()] as $comparison) {
            if ($comparison?->status === 'confirmed' && $comparison->pairs()
                ->where('regulation_unit_id', $unitId)->where('verdict', 'conflict')->exists()) {
                return 'messages.regulation.excluded_citation';
            }
        }

        return 'messages.regulation.compare_before_citing';
    }

    public function forPending(DisciplinaryCase $case, DocumentType $type): array
    {
        $comparison = null;
        if ($type === DocumentType::DecisionLetter) {
            $comparison = $case->documents()->where('document_type', DocumentType::Recommendations->value)
                ->where('status', 'issued')->latest('version')->first()?->regulationComparison;
        }
        $comparison ??= app(RegulationComparisons::class)->pending($case, $type);

        return $this->forComparisons($case, $comparison);
    }

    public function forComparison(?RegulationComparison $comparison, DisciplinaryCase $case): array
    {
        if (! $comparison || ! in_array($comparison->status, ['confirmed', 'skipped'], true)) {
            return [];
        }
        if ($comparison->status === 'skipped') {
            return $this->regulationIds($case);
        }

        return array_map('intval', $comparison->pairs()->where('verdict', 'conflict')->pluck('regulation_unit_id')->all());
    }

    private function regulationIds(DisciplinaryCase $case): array
    {
        return array_map('intval', LegalUnit::query()->citableFor($case)
            ->whereHas('law', fn ($law) => $law->where('kind', 'internal_regulation'))
            ->pluck('legal_units.id')->all());
    }
}
