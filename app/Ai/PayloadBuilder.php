<?php

namespace App\Ai;

use App\Enums\DocumentType;
use App\Models\CaseWitness;
use App\Models\CommitteePoolMember;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\ReportWitness;
use App\Models\User;
use App\Services\Cases\CaseLinks;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayloadBuilder
{
    private array $restoration = [];

    public function comparison(DisciplinaryCase $case, Collection $units): array
    {
        $this->restoration = [];
        $this->names($case);

        return [
            'violation_type' => $this->minimize($case->violation_type ?? ''),
            'incident_description' => $this->minimize($case->description ?? ''),
            'candidate_units' => $units->map(fn ($unit) => [
                'id' => $unit->id, 'kind' => $unit->law->kind->value,
                'official_text' => $unit->official_text,
            ])->all(),
        ];
    }

    public function build(DisciplinaryCase $case, DocumentType $type, Collection $units, ?User $viewer = null): array
    {
        $this->restoration = [];
        $this->names($case);
        $payload = ['document_type' => $type->value,
            'candidate_units' => $units->map(fn ($unit) => [
                'id' => $unit->id, 'law_name' => $unit->law->name,
                'article' => $unit->article_number, 'clause' => $unit->clause_path,
                'official_text' => $unit->official_text,
            ])->all()];
        if ($type !== DocumentType::DecisionLetter) {
            $payload['incident'] = [
                'violation_type' => $this->minimize($case->violation_type),
                'place' => $this->minimize($case->location?->name ?? $case->violation_place_freetext ?? ''),
                'time' => $case->violation_time,
                'description' => $this->minimize($case->description ?? ''),
                'alleged_responsible_person' => $this->minimize($case->allegedResponsibleEmployee?->name ?? $case->alleged_responsible_person_freetext ?? ''),
                'position' => $this->minimize($case->employee?->position ?? ''),
                'department' => $this->minimize($case->employee?->department ?? ''),
            ];
            $payload['related_cases'] = $viewer === null ? [] : app(CaseLinks::class)->visibleRelated($case, $viewer)
                ->filter(fn (array $row) => $row['in_error'] === null)->take(10)
                ->map(fn (array $row) => [
                    'date' => CarbonImmutable::parse($row['case']->violation_time)->toDateString(),
                    'violation_type' => $this->minimize($row['case']->violation_type ?? ''),
                    'outcome' => $row['case']->outcome?->value,
                ])->values()->all();
        }
        if ($type === DocumentType::Recommendations) {
            $payload['employee_response'] = $case->confirmedResponseText() === null ? null : $this->minimize($case->confirmedResponseText());
            $payload['minutes'] = $this->minimize($case->committee_session_notes ?? '');
            $payload['outcome'] = $case->outcome?->value;
            $payload['defense_acceptance'] = $case->defense_acceptance?->value;
            $payload['majority_reached'] = (bool) $case->session_majority_reached;
            $links = DB::table('case_links')->where('case_id', $case->id)->where('link_type', 'prior_record')->pluck('linked_case_id');
            $payload['prior_record_context_only'] = DisciplinaryCase::query()->whereIn('id', $links)->get()
                ->map(fn ($prior) => ['type' => $this->minimize($prior->violation_type),
                    'date' => $prior->violation_time, 'outcome' => $prior->outcome?->value])->all();
        }
        if ($type === DocumentType::DecisionLetter) {
            $recommendations = $case->documents()->where('document_type', DocumentType::Recommendations->value)
                ->where('status', 'issued')->orderByDesc('version')->first();
            $payload['issued_recommendations'] = $recommendations?->contents()->whereIn('language_code', ['ar', 'en'])->get()
                ->mapWithKeys(fn ($content) => [$content->language_code => [
                    'findings' => $this->minimize($content->body),
                    'recommendations' => $this->minimize($content->secondary_body ?? ''),
                ]])->all() ?? [];
            $payload['outcome'] = $case->outcome?->value;
            $payload['penalty_type'] = $case->outcome?->value;
            $payload['defense_acceptance'] = $case->defense_acceptance?->value;
        }

        return $payload;
    }

    public function restore(array $output): array
    {
        array_walk_recursive($output, function (&$value): void {
            if (is_string($value)) {
                $value = strtr($value, $this->restoration);
            }
        });

        return $output;
    }

    private function names(DisciplinaryCase $case): void
    {
        $names = [];
        $add = function (?string $name, string $role) use (&$names): void {
            $name = trim((string) $name);
            if (mb_strlen($name) >= 3 && ! in_array($name, $names, true)) {
                $this->restoration['⟦'.$role.'⟧'] = $name;
                $names[] = $name;
            }
        };
        $add($case->employee?->name, 'الموظف');
        $add($case->employee?->name_en, 'الموظف الإنجليزي');
        $add($case->allegedResponsibleEmployee?->name ?? $case->alleged_responsible_person_freetext, 'الشخص المنسوب إليه');
        foreach (CaseWitness::query()->where('case_id', $case->id)->pluck('name') as $index => $name) {
            $add($name, 'الشاهد '.($index + 1));
        }
        if ($case->violation_report_id) {
            foreach (ReportWitness::query()->where('violation_report_id', $case->violation_report_id)->pluck('name') as $index => $name) {
                $add($name, 'شاهد البلاغ '.($index + 1));
            }
        }
        foreach (CommitteePoolMember::query()->where('company_id', $case->company_id)->get() as $index => $member) {
            $add($member->person_name, 'عضو اللجنة '.($index + 1));
            $add($member->person_name_en, 'عضو اللجنة الإنجليزي '.($index + 1));
        }
        foreach (Employee::query()->where('company_id', $case->company_id)->get() as $index => $employee) {
            $add($employee->name, 'موظف '.($index + 1));
            $add($employee->name_en, 'موظف إنجليزي '.($index + 1));
        }
        foreach (User::query()->where('company_id', $case->company_id)->get() as $index => $user) {
            $add($user->name, 'مستخدم '.($index + 1));
        }
        uksort($this->restoration, fn ($a, $b) => mb_strlen($this->restoration[$b]) <=> mb_strlen($this->restoration[$a]));
    }

    private function minimize(string $value): string
    {
        foreach ($this->restoration as $token => $name) {
            // Whole words only: a short name such as "علي" must not rewrite part of "عليه".
            $value = preg_replace('/(?<![\p{L}\p{M}])'.preg_quote($name, '/').'(?![\p{L}\p{M}])/iu', $token, $value);
        }
        $value = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u', '⟦بريد⟧', $value);
        $value = preg_replace('/(?<!\p{N})[+\p{N}][\p{N}\s()\-]{6,}\p{N}/u', '⟦رقم⟧', $value);
        $value = preg_replace('/[\p{N}]{7,}/u', '⟦رقم⟧', $value);

        return $value;
    }
}
