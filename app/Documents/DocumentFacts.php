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
        $name = trim((string) $this->required($employee->name, 'employee.name'));
        $facts = [
            'employee_name' => $name,
            'employee_first_name' => explode(' ', $name)[0],
            'employee_code' => $employee->employee_code,
            'position' => $this->required($employee->position, 'employee.position'),
            'department' => $employee->department,
            'branch' => $case->branch?->name,
            'hire_date' => IraqiDate::format($this->date($employee->hire_date, 'employee.hire_date'), $language),
            'issue_date' => IraqiDate::format($issueDate, $language),
            'reference_number' => $reference,
            'response_deadline' => IraqiDate::format($issueDate->startOfDay()->addDays((int) config('displanery.deadlines.response_days')), $language),
            'complaint_date' => IraqiDate::format($this->date($case->violation_time, 'case.violation_time'), $language),
            'penalty' => match ($document->penalty_type?->value) {
                'warning' => $language === 'en' ? 'written warning' : 'إنذار خطي',
                'termination' => $language === 'en' ? 'termination' : 'إنهاء خدمة',
                default => null,
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
                $extra['attendees'][] = ['name' => $this->required($member?->employee?->name ?? $member?->person_name, 'attendee.name'),
                    'role' => $member?->role_type?->value, 'is_chair' => $attendance->is_chair];
            }
            foreach (PoolRole::cases() as $role) {
                if (! collect($extra['attendees'])->contains('role', $role->value)) {
                    throw new MissingDocumentFact("attendee.{$role->value}");
                }
            }
            if (! collect($extra['attendees'])->contains(fn ($attendee) => $attendee['role'] === PoolRole::Legal->value && $attendee['is_chair'])) {
                throw new MissingDocumentFact('legal_chair');
            }
        }
        if ($document->document_type === DocumentType::DecisionLetter) {
            if ($case->outcome?->value !== $document->penalty_type?->value) {
                throw new MissingDocumentFact('matching_penalty_outcome');
            }
            $gm = Employee::query()->where('company_id', $case->company_id)->where('is_general_manager', true)->where('is_active', true)->first();
            $facts['gm_name'] = $this->required($gm?->name, 'general_manager');
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
                    .' — '.$this->required($old->violation_type, 'prior_case_type')
                    .' — '.$this->required($old->outcome?->value, 'prior_case_outcome');
            }
        }

        return [$facts, $extra, $employee->gender];
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
