<?php

namespace App\Services\Reports;

use App\Enums\CaseStatus;
use App\Models\DisciplinaryCase;
use App\Models\Incident;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\AuditLogger;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\ConflictClearance;
use App\Services\Cases\IncidentService;
use App\Services\Employees\EmployeePicker;
use App\Support\Search\SearchText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReportCaseOpening
{
    public function __construct(private EmployeePicker $picker, private ReportService $reports,
        private CaseInsights $insights, private ConflictClearance $conflicts,
        private CaseOpening $opening, private IncidentService $incidents, private AuditLogger $audit) {}

    public function people(ViolationReport $report, User $actor): array
    {
        $rows = [];
        $linked = $report->employees()->orderBy('employees.id')->get();
        if ($linked->isEmpty() && $report->employee_id) {
            $linked = collect([$report->employee]);
        }
        foreach ($linked as $employee) {
            $rows[] = ['name' => $employee->name, 'kind' => 'linked', 'linked_id' => $employee->id,
                'selected_id' => $employee->id, 'candidates' => []];
        }
        foreach ($report->free_employee_names ?? [] as $name) {
            $candidates = array_slice($this->picker->search($actor, (int) $report->branch_id, $name), 0, 3);
            $exact = array_values(array_filter($candidates, fn (array $candidate): bool => SearchText::normalize($candidate['name']) === SearchText::normalize($name)
                || SearchText::normalize((string) $candidate['name_en']) === SearchText::normalize($name)));
            $rows[] = ['name' => $name, 'kind' => 'free', 'linked_id' => null,
                'selected_id' => count($exact) === 1 ? $exact[0]['id'] : null, 'candidates' => $candidates];
        }
        if ($rows === []) {
            $rows[] = ['name' => __('Employees not named'), 'kind' => 'unknown',
                'linked_id' => null, 'selected_id' => null, 'candidates' => []];
        }

        return $rows;
    }

    public function save(ViolationReport $report, User $actor, array $rows, array $facts): DisciplinaryCase
    {
        Gate::forUser($actor)->authorize('create', DisciplinaryCase::class);
        $expected = $this->people($report, $actor);
        abort_unless(count($rows) === count($expected), 422);
        $selected = [];
        $resolved = [];
        foreach ($rows as $index => $row) {
            abort_unless(($row['name'] ?? null) === $expected[$index]['name']
                && ($row['linked_id'] ?? null) === $expected[$index]['linked_id'], 422);
            $id = (int) ($row['selected_id'] ?? 0);
            if (! $id) {
                throw ValidationException::withMessages(["people.$index" => __('Match each named person to an employee before opening a case.')]);
            }
            $this->picker->employee($actor, (int) $report->branch_id, $id);
            if (($row['linked_id'] ?? null) !== null && (int) $row['linked_id'] !== $id) {
                throw ValidationException::withMessages(["people.$index" => __('The linked employee cannot be changed.')]);
            }
            $selected[] = $id;
            if ($expected[$index]['kind'] === 'free') {
                $resolved[] = $id;
            }
        }
        if ($selected === []) {
            throw ValidationException::withMessages(['people' => __('Choose an employee')]);
        }
        $selected = array_values(array_unique($selected));

        return $this->reports->convert($report, $actor, null, $facts + [
            'employee_id' => $selected[0], 'employee_ids' => $selected,
            'resolved_free_employee_ids' => $resolved,
        ]);
    }

    public function findings(DisciplinaryCase $draft): array
    {
        $members = $draft->incident_id
            ? Incident::query()->findOrFail($draft->incident_id)->cases()->where('status', CaseStatus::Draft->value)->get()
            : collect([$draft]);
        $duplicates = [];
        $conflicts = [];
        foreach ($members as $member) {
            foreach ($this->insights->duplicateCandidates($member)['cases'] as $other) {
                $duplicates[] = ['id' => $other->id, 'number' => $other->case_number,
                    'employee' => $member->employee?->name, 'member_id' => $member->id];
            }
            foreach ($this->conflicts->conflicts($member) as $row) {
                $conflicts[] = ['name' => $row['member']->person_name ?: $row['member']->employee?->name,
                    'employee' => $member->employee?->name];
            }
        }

        return ['duplicates' => $duplicates, 'conflicts' => $conflicts];
    }

    public function open(DisciplinaryCase $draft, User $actor, ?int $duplicateId = null, array $duplicateIds = []): DisciplinaryCase
    {
        return DB::transaction(function () use ($draft, $actor, $duplicateId, $duplicateIds): DisciplinaryCase {
            Gate::forUser($actor)->authorize('update', $draft);
            $links = [];
            $candidates = collect($this->findings($draft)['duplicates']);
            if ($duplicateId !== null) {
                $eligible = $candidates->firstWhere('id', $duplicateId);
                abort_unless($eligible !== null, 422);
                $links[$eligible['member_id']] = $duplicateId;
            }
            foreach ($duplicateIds as $memberId => $otherId) {
                $eligible = $candidates->first(fn (array $row): bool => $row['member_id'] === (int) $memberId && $row['id'] === (int) $otherId);
                abort_unless($eligible !== null, 422);
                $links[(int) $memberId] = (int) $otherId;
            }
            $case = $draft->incident_id
                ? $this->incidents->openDrafts(Incident::query()->findOrFail($draft->incident_id), $actor)
                : $this->opening->open($draft, $actor);
            foreach ($links as $memberId => $otherId) {
                $other = DisciplinaryCase::query()->where('company_id', $actor->company_id)->findOrFail($otherId);
                $member = DisciplinaryCase::query()->where('company_id', $actor->company_id)->findOrFail($memberId);
                $this->insights->linkDuplicate($member, $other);
            }
            $this->audit->log('report.case_opening_choice', $case,
                ['choice' => $links === [] ? 'separate' : 'duplicate_link', 'duplicate_case_ids' => $links], $actor);

            return $case;
        });
    }
}
