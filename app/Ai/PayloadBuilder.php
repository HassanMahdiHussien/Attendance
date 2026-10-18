<?php

namespace App\Ai;

use App\Documents\IraqiDate;
use App\Enums\DocumentType;
use App\Exceptions\TransitionBlocked;
use App\Models\Branch;
use App\Models\CaseWitness;
use App\Models\CommitteePoolMember;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\ReportWitness;
use App\Models\User;
use App\Services\Cases\CaseLinks;
use App\Services\Cases\IncidentPrivacy;
use App\Support\Format\Violation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PayloadBuilder
{
    private array $restoration = [];

    private array $reportEmployeeTokens = [];

    public function reportConversation(int $companyId, User $reporter, array $transcript, ?int $branchId = null): array
    {
        $this->restoration = [];
        $this->reportEmployeeTokens = [];
        $index = 0;
        foreach (Employee::query()->where('company_id', $companyId)
            ->when($branchId !== null, fn ($query) => $query->orderByRaw('branch_id = ? DESC', [$branchId]))->get() as $employee) {
            $token = '⟦employee_'.(++$index).'⟧';
            $this->reportEmployeeTokens[$token] = (int) $employee->id;
            foreach ([$employee->name, $employee->name_en, $employee->employee_code] as $name) {
                if (trim((string) $name) !== '') {
                    $this->restoration[$token] = $employee->name;
                    $names[$name] ??= $token;
                }
            }
        }
        $branchIndex = 0;
        foreach (Branch::query()->where('company_id', $companyId)->get() as $branch) {
            if (trim((string) $branch->name) !== '') {
                $token = '⟦branch_'.(++$branchIndex).'⟧';
                $names[$branch->name] = $token;
                $this->restoration[$token] = $branch->name;
            }
        }
        if (trim((string) $reporter->name) !== '') {
            $names[$reporter->name] = '⟦reporter⟧';
            $this->restoration['⟦reporter⟧'] = $reporter->name;
        }
        $names = $names ?? [];
        uksort($names, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));

        return array_map(function (array $line) use ($names): array {
            $value = (string) ($line['text'] ?? '');
            foreach ($names as $name => $token) {
                $value = preg_replace('/(?<![\p{L}\p{M}])'.preg_quote($name, '/').'(?![\p{L}\p{M}])/iu', $token, $value);
            }

            $value = $this->minimize($value);
            $value = preg_replace('/\b(?:salary|wage|راتب|الراتب|أجر)\s*[:=]?\s*\d[\d,.]*/iu', '⟦مبلغ⟧', $value);
            $value = preg_replace('/\b\d[\d,.]*\s*(?:IQD|د\\.?ع|دينار|USD|\$)\b/iu', '⟦مبلغ⟧', $value);
            $value = preg_replace('/\b(?:id|identifier|هوية|رقم وظيفي)\s*[:#-]?\s*\d+/iu', '⟦معرّف⟧', $value);

            return ['role' => $line['role'], 'text' => $value];
        }, $transcript);
    }

    public function reportEmployeeId(string $token): ?int
    {
        return $this->reportEmployeeTokens[$token] ?? null;
    }

    public function minimizeIntake(array $input): array
    {
        $this->restoration = [];

        $minimized = [];
        foreach ($input as $key => $value) {
            $minimized[$key] = $key === 'time' ? (string) $value : $this->minimize((string) $value);
        }

        return $minimized;
    }

    public function comparison(DisciplinaryCase $case, Collection $units): array
    {
        $this->restoration = [];
        $this->names($case);
        $description = $this->description($case);
        $this->assertPrivate($case, $description.' '.Violation::name($case, 'ar'));

        return [
            'violation_type' => $this->minimize(Violation::name($case, 'ar')),
            'incident_description' => $this->minimize($description),
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
        $description = $type === DocumentType::DecisionLetter ? '' : $this->description($case);
        $this->assertPrivate($case, $description);
        $responsible = $case->allegedResponsibleEmployee?->name ?? $case->alleged_responsible_person_freetext ?? '';
        if (app(IncidentPrivacy::class)->containsOtherMemberName($case, $responsible)) {
            $responsible = '';
        }
        $this->assertPrivate($case, implode(' ', array_filter([
            Violation::name($case, 'ar'), $case->location?->name, $case->violation_place_freetext,
            $responsible,
        ])));
        $payload = ['document_type' => $type->value,
            'candidate_units' => $units->map(fn ($unit) => [
                'id' => $unit->id, 'law_name' => $unit->law->name,
                'article' => $unit->article_number, 'clause' => $unit->clause_path,
                'official_text' => $unit->official_text,
            ])->all()];
        if ($type !== DocumentType::DecisionLetter) {
            $payload['incident'] = [
                'violation_type' => $this->minimize(Violation::name($case, 'ar')),
                'place' => $this->minimize($case->location?->name ?? $case->violation_place_freetext ?? ''),
                'time' => $case->violation_time,
                // Ready-written dates in the documents' own style, so the prose never shows an ISO date.
                'date_written' => $case->violation_time === null ? null : [
                    'ar' => IraqiDate::format(CarbonImmutable::parse($case->violation_time)->setTimezone('Asia/Baghdad'), 'ar'),
                    'en' => IraqiDate::format(CarbonImmutable::parse($case->violation_time)->setTimezone('Asia/Baghdad'), 'en'),
                ],
                'description' => $this->minimize($description),
                'alleged_responsible_person' => $this->minimize($responsible),
                'position' => $this->minimize($case->employee?->position ?? ''),
                'department' => $this->minimize($case->employee?->department ?? ''),
                // Arabic verbs, pronouns and nouns agree with gender; without it every letter defaulted to masculine.
                'employee_gender' => $case->employee?->gender?->value ?? 'unspecified',
            ];
            $payload['related_cases'] = $viewer === null ? [] : app(CaseLinks::class)->visibleRelated($case, $viewer)
                ->filter(fn (array $row) => $row['in_error'] === null)->take(10)
                ->map(fn (array $row) => [
                    'date' => CarbonImmutable::parse($row['case']->violation_time)->toDateString(),
                    'violation_type' => $this->minimize(Violation::name($row['case'], 'ar')),
                    'outcome' => $row['case']->outcome?->value,
                ])->values()->all();
            if ($type === DocumentType::DefenseLetter) {
                $payload['intake_answers'] = $case->incident_id !== null ? [] : $case->intakeQuestions()
                    ->whereNotNull('answer')->orderBy('id')->get()
                    ->map(function ($row) use ($case): array {
                        $this->assertPrivate($case, $row->question.' '.$row->answer);

                        return ['aspect' => $row->aspect, 'question' => $this->minimize($row->question),
                            'answer' => $this->minimize($row->answer)];
                    })->all();
            }
        }
        if ($type === DocumentType::Recommendations) {
            $this->assertPrivate($case, (string) $case->confirmedResponseText().' '.(string) $case->committee_session_notes);
            $payload['employee_response'] = $case->confirmedResponseText() === null ? null : $this->minimize($case->confirmedResponseText());
            $payload['minutes'] = $this->minimize($case->committee_session_notes ?? '');
            $payload['outcome'] = $case->outcome?->value;
            $payload['defense_acceptance'] = $case->defense_acceptance?->value;
            $payload['majority_reached'] = (bool) $case->session_majority_reached;
            $links = DB::table('case_links')->where('case_id', $case->id)->where('link_type', 'prior_record')->pluck('linked_case_id');
            $payload['prior_record_context_only'] = DisciplinaryCase::query()->whereIn('id', $links)->get()
                ->map(fn ($prior) => ['type' => $this->minimize(Violation::name($prior, 'ar')),
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

    private function assertPrivate(DisciplinaryCase $case, string $text): void
    {
        if (app(IncidentPrivacy::class)->containsOtherMemberName($case, $text)) {
            throw new TransitionBlocked(__('messages.incident.other_member_in_text'));
        }
    }

    private function description(DisciplinaryCase $case): string
    {
        if ($case->incident_id === null) {
            return (string) $case->description;
        }
        if (blank($case->case_specific_description)) {
            throw new TransitionBlocked(__('messages.incident.case_description_required'));
        }

        return $case->case_specific_description;
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
        // «13-10-2026» has a phone number's shape, and masking it hid the date from a reporter who wrote it
        // three times. Dates are fenced off before the phone patterns run and put back afterwards.
        $dates = [];
        $value = preg_replace_callback('~(?<!\p{N})(?:\p{N}{1,2}[-/.]\p{N}{1,2}[-/.]\p{N}{4}|\p{N}{4}[-/.]\p{N}{1,2}[-/.]\p{N}{1,2})(?:\s+\p{N}{1,2}:\p{N}{2})?(?!\p{N})~u',
            function (array $match) use (&$dates): string {
                $dates[] = $match[0];

                return "\u{E000}".(count($dates) - 1)."\u{E001}";
            }, $value);
        $value = preg_replace('/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u', '⟦بريد⟧', $value);
        $value = preg_replace('/(?<!\p{N})[+\p{N}][\p{N}\s()\-]{6,}\p{N}/u', '⟦رقم⟧', $value);
        $value = preg_replace('/[\p{N}]{7,}/u', '⟦رقم⟧', $value);

        return preg_replace_callback('/\x{E000}(\d+)\x{E001}/u', fn (array $match): string => $dates[(int) $match[1]], $value);
    }
}
