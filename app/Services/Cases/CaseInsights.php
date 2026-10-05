<?php

namespace App\Services\Cases;

use App\Enums\CaseLinkType;
use App\Models\CaseLink;
use App\Models\DisciplinaryCase;
use App\Models\ViolationReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CaseInsights
{
    public function duplicateCandidates(DisciplinaryCase|ViolationReport $source): array
    {
        if ($source->employee_id === null) {
            return ['cases' => collect(), 'reports' => collect()];
        }
        $when = CarbonImmutable::parse($source instanceof DisciplinaryCase ? $source->violation_time : $source->occurred_at);
        $start = $when->subHours(48);
        $end = $when->addHours(48);

        return [
            'cases' => DisciplinaryCase::query()->where('company_id', $source->company_id)->where('employee_id', $source->employee_id)
                ->whereKeyNot($source instanceof DisciplinaryCase ? $source->id : 0)
                ->whereBetween('violation_time', [$start, $end])->get(),
            'reports' => ViolationReport::query()->where('company_id', $source->company_id)->where('employee_id', $source->employee_id)
                ->whereKeyNot($source instanceof ViolationReport ? $source->id : 0)
                ->whereBetween('occurred_at', [$start, $end])->get(),
        ];
    }

    public function linkDuplicate(DisciplinaryCase $case, DisciplinaryCase $other): CaseLink
    {
        if ($case->company_id !== $other->company_id || $case->id === $other->id) {
            throw new \DomainException('Cases must be distinct and belong to the same company.');
        }

        return CaseLink::firstOrCreate([
            'company_id' => $case->company_id, 'case_id' => $case->id,
            'linked_case_id' => $other->id, 'link_type' => CaseLinkType::DuplicateMerge->value,
        ]);
    }

    public function linkPriors(DisciplinaryCase $case): void
    {
        foreach ($this->priorCases($case) as $prior) {
            CaseLink::firstOrCreate([
                'company_id' => $case->company_id, 'case_id' => $case->id,
                'linked_case_id' => $prior->id, 'link_type' => CaseLinkType::PriorRecord->value,
            ]);
        }
    }

    public function priorBanner(DisciplinaryCase $case): array
    {
        $prior = $this->priorCases($case);
        $cutoff = CarbonImmutable::parse($case->created_at)->subMonthsNoOverflow(12);

        return ['count' => $prior->count(), 'cases' => $prior->map(fn (DisciplinaryCase $row) => [
            'case_id' => $row->id, 'date' => CarbonImmutable::parse($row->created_at)->toDateString(),
            'violation_type' => $row->violation_type, 'outcome' => $row->outcome?->value,
            'same_type_repeat' => $row->violation_type === $case->violation_type && CarbonImmutable::parse($row->created_at)->gte($cutoff),
        ])->all()];
    }

    public function responsiblePersonPattern(DisciplinaryCase $case): array
    {
        if ($case->alleged_responsible_employee_id === null) {
            return ['count' => 0, 'flagged' => false];
        }
        $count = DisciplinaryCase::query()->where('company_id', $case->company_id)->where('branch_id', $case->branch_id)
            ->where('alleged_responsible_employee_id', $case->alleged_responsible_employee_id)
            ->whereKeyNot($case->id)->where('created_at', '>=', CarbonImmutable::parse($case->created_at)->subMonthsNoOverflow(12))->count();

        return ['count' => $count, 'flagged' => $count >= 2];
    }

    private function priorCases(DisciplinaryCase $case): Collection
    {
        return DisciplinaryCase::query()->where('company_id', $case->company_id)->where('employee_id', $case->employee_id)
            ->whereKeyNot($case->id)->where('created_at', '<', $case->created_at)->orderByDesc('created_at')->get();
    }
}
