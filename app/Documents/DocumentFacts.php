<?php

namespace App\Documents;

use App\Enums\DocumentType;
use App\Enums\EmployeeGender;
use App\Enums\PoolRole;
use App\Models\CommitteeMandate;
use App\Models\CommitteeSessionAttendance;
use App\Models\DisciplinaryCase;
use App\Models\Document;
use App\Models\Employee;
use App\Services\Documents\DocumentIssuer;
use App\Support\Format\OutcomeLabel;
use App\Support\Format\Violation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DocumentFacts
{
    public function collect(Document $document, string $language, bool $draft = false): array
    {
        $case = $document->disciplinaryCase;
        $employee = $case->employee;
        if (! in_array($employee?->gender, [EmployeeGender::Male, EmployeeGender::Female], true)) {
            throw new MissingDocumentFact('employee.gender');
        }
        $issueDate = $draft ? CarbonImmutable::now('Asia/Baghdad') : CarbonImmutable::parse($document->issued_at, 'Asia/Baghdad');
        $reference = $draft ? app(DocumentIssuer::class)->previewReference($document) : $document->reference_number;
        if (blank($reference)) {
            throw new MissingDocumentFact('reference_number');
        }
        $name = trim((string) $this->required($this->localized($employee, 'name', $language), 'employee.name'));
        $facts = [
            'employee_name' => $name,
            'employee_first_name' => explode(' ', $name)[0],
            'employee_code' => $employee->employee_code,
            'position' => $this->required($this->localized($employee, 'position', $language), 'employee.position'),
            'department' => $this->localized($employee, 'department', $language),
            'branch' => $case->branch?->name,
            'hire_date' => IraqiDate::format($this->date($employee->hire_date, 'employee.hire_date'), $language),
            'issue_date' => IraqiDate::format($issueDate, $language),
            'reference_number' => $reference,
            'response_deadline' => IraqiDate::format($issueDate->startOfDay()->addDays((int) config('displanery.deadlines.response_days')), $language),
            'complaint_date' => IraqiDate::format($this->date($case->violation_time, 'case.violation_time'), $language)
                .($case->violation_time_known ? '' : ' ('.($language === 'ar' ? 'الوقت غير مذكور' : 'time not stated').')'),
            'penalty' => match ($document->penalty_type?->value) {
                'warning' => $language === 'en' ? 'written warning' : 'إنذار خطي',
                'termination' => $language === 'en' ? 'termination' : 'إنهاء خدمة',
                default => null,
            },
            'outcome' => $language === 'en' ? match ($case->outcome?->value) {
                'warning' => 'a written warning', 'termination' => 'termination of employment',
                'recommendation_only' => 'a recommendation without a penalty', default => null,
            } : match ($case->outcome?->value) {
                // Phrases completing "توصي اللجنة بـ…"; the short labels (OutcomeLabel) read badly there.
                'warning' => 'توجيه إنذار خطي', 'termination' => 'إنهاء خدمة الموظف',
                'recommendation_only' => 'الاكتفاء بالتوصية دون فرض عقوبة', default => null,
            },
        ];
        $extra = ['attendees' => [], 'prior_cases' => []];
        if ($document->document_type !== DocumentType::DefenseLetter) {
            $mandate = $case->defense_letter_issued_at
                ? CommitteeMandate::query()->where('company_id', $case->company_id)->inEffectOn($case->defense_letter_issued_at)->orderByDesc('effective_from')->first()
                : null;
            if (! $mandate) {
                throw new MissingDocumentFact('committee_mandate');
            }
            $facts['mandate_number'] = $this->required($mandate->order_number, 'mandate_number');
            $facts['mandate_date'] = IraqiDate::format($this->date($mandate->order_date, 'mandate_date'), $language);
            $facts['session_date'] = IraqiDate::format($this->date($case->committee_session_held_at, 'session_date'), $language);
            $attendees = CommitteeSessionAttendance::query()->where('case_id', $case->id)
                ->whereHas('committeePoolMember', fn ($query) => $query->where('mandate_id', $mandate->id))
                ->with('committeePoolMember.employee')->get();
            foreach ($attendees as $attendance) {
                $member = $attendance->committeePoolMember;
                $extra['attendees'][] = ['name' => $this->required($member?->employee
                    ? $this->localized($member->employee, 'name', $language)
                    : ($language === 'en' && filled($member?->person_name_en) ? $member->person_name_en : $member?->person_name), 'attendee.name'),
                    'role' => $member?->role_type?->value, 'is_chair' => $attendance->is_chair,
                    'is_female' => $member?->employee?->gender === EmployeeGender::Female];
            }
            if (count($extra['attendees']) < 3 || ! collect($extra['attendees'])->contains('role', PoolRole::LaborUnion->value)
                || ! collect($extra['attendees'])->contains('is_chair', true)) {
                throw new MissingDocumentFact('committee_attendance');
            }
            if ($document->document_type === DocumentType::Recommendations && ! $draft) {
                $approvals = $document->approvals()->whereNull('invalidated_at')->where('decision', 'approved')
                    ->with('approver')->get()->keyBy(fn ($approval) => $approval->role_category->value);
                foreach (PoolRole::cases() as $role) {
                    $extra['approvers'][$role->value] = $this->required($approvals->get($role->value)?->approver?->name,
                        "approver.{$role->value}");
                }
            }
        }
        if ($document->document_type === DocumentType::DecisionLetter) {
            if ($case->outcome?->value !== $document->penalty_type?->value) {
                throw new MissingDocumentFact('matching_penalty_outcome');
            }
            $gm = Employee::query()->where('company_id', $case->company_id)->where('is_general_manager', true)->where('is_active', true)->first();
            $facts['gm_name'] = $this->required($gm ? $this->localized($gm, 'name', $language) : null, 'general_manager');
            $union = collect($extra['attendees'])->firstWhere('role', PoolRole::LaborUnion->value);
            $facts['union_rep_name'] = $this->required($union['name'] ?? null, 'union_representative');
            $extra['defense_acceptance'] = $this->required($case->defense_acceptance?->value, 'defense_acceptance');
            $this->required($facts['penalty'], 'penalty');
        }
        if (($document->document_type === DocumentType::DefenseLetter && $case->include_prior_record)
            || $document->document_type === DocumentType::Recommendations) {
            $links = DB::table('case_links')->where('case_id', $case->id)->where('link_type', 'prior_record')->pluck('linked_case_id');
            $prior = DisciplinaryCase::query()->whereIn('id', $links)->get();
            foreach ($prior as $old) {
                $extra['prior_cases'][] = IraqiDate::format($this->date($old->violation_time, 'prior_case_date'), $language)
                    .($old->violation_time_known ? '' : ' ('.($language === 'ar' ? 'الوقت غير مذكور' : 'time not stated').')')
                    .' — '.$this->required(Violation::name($old, $language), 'prior_case_type')
                    .' — '.($language === 'ar'
                        ? OutcomeLabel::arabic($old->outcome)
                        : match ($old->outcome?->value) {
                            'recommendation_only' => 'recommendation without penalty',
                            'warning' => 'warning',
                            'termination' => 'termination',
                            default => 'no outcome yet',
                        });
            }
        }

        return [$facts, $extra, $employee->gender];
    }

    private function localized(Employee $employee, string $field, string $language): ?string
    {
        $english = $field.'_en';

        return $language === 'en' && filled($employee->{$english}) ? $employee->{$english} : $employee->{$field};
    }

    private function required(mixed $value, string $name): mixed
    {
        if (blank($value)) {
            throw new MissingDocumentFact($name);
        }

        return $value;
    }

    private function date(mixed $value, string $name): CarbonImmutable
    {
        return CarbonImmutable::parse($this->required($value, $name), 'Asia/Baghdad');
    }
}
