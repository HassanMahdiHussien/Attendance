<?php

namespace App\Services\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Incident;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Support\Access\CaseVisibility;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class IncidentService
{
    public function __construct(private CaseDrafts $drafts, private CaseOpening $opening,
        private CaseEventLogger $events, private AuditLogger $audit) {}

    public function createForEmployees(array $employeeIds, array $facts, User $actor, ?ViolationReport $report = null): Incident
    {
        Gate::forUser($actor)->authorize('create', DisciplinaryCase::class);
        $ids = array_values(array_unique(array_map('intval', $employeeIds)));
        if ($ids === [] || count($ids) !== count($employeeIds)) {
            throw ValidationException::withMessages(['employeeIds' => __('messages.incident.unique_employees_required')]);
        }
        $branchId = (int) ($facts['branch_id'] ?? 0);
        if (Employee::query()->where('company_id', $actor->company_id)->where('branch_id', $branchId)
            ->where('is_active', true)->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['employeeIds' => __('messages.incident.employees_branch_required')]);
        }
        if ($report !== null && ((int) $report->company_id !== (int) $actor->company_id
            || (int) $report->branch_id !== $branchId || $report->case_id !== null)) {
            abort(403);
        }

        return DB::transaction(function () use ($ids, $facts, $actor, $report, $branchId): Incident {
            $incident = Incident::create(['company_id' => $actor->company_id, 'branch_id' => $branchId,
                'created_by_user_id' => $actor->id]);
            foreach ($ids as $employeeId) {
                $case = $this->drafts->create(array_replace($facts, [
                    'employee_id' => $employeeId, 'incident_id' => $incident->id,
                ]), $actor, $report);
                $this->events->log($case, 'incident_member_added', ['incident_id' => $incident->id], actor: $actor);
            }
            $this->audit->log('incident.created', $incident, ['case_count' => count($ids)], $actor);

            return $incident;
        });
    }

    public function addEmployee(Incident $incident, int $employeeId, User $actor): DisciplinaryCase
    {
        $this->requireHr($actor);
        Gate::forUser($actor)->authorize('create', DisciplinaryCase::class);

        return DB::transaction(function () use ($incident, $employeeId, $actor): DisciplinaryCase {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            abort_unless($this->canManageAll($incident, $actor), 403);
            $source = CaseVisibility::for($actor)->where('incident_id', $incident->id)->orderBy('id')->firstOrFail();
            $employee = Employee::query()->where('company_id', $incident->company_id)->where('branch_id', $incident->branch_id)
                ->where('is_active', true)->findOrFail($employeeId);
            if ($incident->cases()->where('employee_id', $employeeId)->exists()) {
                throw ValidationException::withMessages(['employeeId' => __('messages.incident.already_member')]);
            }
            foreach ($incident->cases as $member) {
                foreach ($member->documents()->where('is_locked', true)->with('contents')->get() as $document) {
                    $text = (string) $document->rendered_html.' '.$document->contents
                        ->map(fn ($content) => $content->body.' '.$content->secondary_body)->implode(' ');
                    foreach (array_filter([$employee->name, $employee->name_en]) as $name) {
                        if (mb_stripos($text, trim($name)) !== false) {
                            throw ValidationException::withMessages(['employeeId' => __('messages.incident.issued_document_names_employee')]);
                        }
                    }
                }
            }
            $case = $this->drafts->create($this->sharedFacts($source) + [
                'employee_id' => $employeeId, 'incident_id' => $incident->id,
                'assigned_hr_user_id' => $actor->id,
            ], $actor);
            $this->events->log($case, 'incident_member_added', ['incident_id' => $incident->id], actor: $actor);
            $this->audit->log('incident.employee_added', $incident, ['case_id' => $case->id], $actor);

            return $case;
        });
    }

    public function updateSharedFacts(Incident $incident, array $facts, User $actor): void
    {
        $this->requireHr($actor);
        DB::transaction(function () use ($incident, $facts, $actor): void {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $cases = CaseVisibility::for($actor)->where('incident_id', $incident->id)->lockForUpdate()->get();
            if ($cases->count() !== $incident->cases()->count()) {
                abort(403);
            }
            if ($cases->contains(fn (DisciplinaryCase $case) => $case->defense_letter_issued_at !== null)) {
                throw ValidationException::withMessages(['facts' => __('messages.incident.facts_frozen')]);
            }
            foreach ($cases as $case) {
                Gate::forUser($actor)->authorize('update', $case);
                if (! in_array($case->status, [CaseStatus::Draft, CaseStatus::Open], true)
                    || $case->documents()->exists()) {
                    throw ValidationException::withMessages(['facts' => __('messages.incident.draft_facts_only')]);
                }
                $this->drafts->update($case, array_replace($this->sharedFacts($case), $facts, [
                    'employee_id' => $case->employee_id, 'branch_id' => $incident->branch_id,
                ]), $actor);
                if ($case->case_specific_description !== null) {
                    $case->forceFill(['case_specific_description' => null])->save();
                }
                $this->events->log($case, 'incident_facts_updated', [
                    'incident_id' => $incident->id, 'fields' => array_keys($facts),
                ], actor: $actor);
            }
            $this->audit->log('incident.facts_updated', $incident, ['case_count' => $cases->count()], $actor);
        });
    }

    public function visibleMembers(Incident $incident, User $viewer): Collection
    {
        return CaseVisibility::for($viewer)->where('cases.company_id', $incident->company_id)
            ->where('incident_id', $incident->id)->with('employee')->orderBy('case_number')->get();
    }

    public function canManageAll(Incident $incident, User $actor): bool
    {
        return $this->visibleMembers($incident, $actor)->count() === $incident->cases()->count();
    }

    public function updateCaseDescription(DisciplinaryCase $case, string $description, User $actor): void
    {
        $this->requireHr($actor);
        DB::transaction(function () use ($case, $description, $actor): void {
            $case = CaseVisibility::for($actor)->lockForUpdate()->findOrFail($case->id);
            Gate::forUser($actor)->authorize('update', $case);
            if ($case->incident_id === null || $case->defense_letter_issued_at !== null
                || $case->documents()->exists() || blank($description)
                || app(IncidentPrivacy::class)->containsOtherMemberName($case, $description)) {
                throw ValidationException::withMessages(['caseDescription' => __('messages.incident.case_description_required')]);
            }
            $case->forceFill(['case_specific_description' => $description])->save();
            $this->events->log($case, 'incident_case_description_updated', actor: $actor);
        });
    }

    public function openDrafts(Incident $incident, User $actor): DisciplinaryCase
    {
        $this->requireHr($actor);

        return DB::transaction(function () use ($incident, $actor): DisciplinaryCase {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            abort_unless($this->canManageAll($incident, $actor), 403);
            $cases = $incident->cases()->where('status', CaseStatus::Draft->value)->orderBy('id')->lockForUpdate()->get();
            if ($cases->isEmpty()) {
                throw ValidationException::withMessages(['incident' => __('messages.incident.drafts_required')]);
            }
            foreach ($cases as $case) {
                $this->opening->open($case, $actor);
            }

            return $cases->first()->fresh();
        });
    }

    private function sharedFacts(DisciplinaryCase $case): array
    {
        return [
            'branch_id' => $case->branch_id, 'location_id' => $case->location_id,
            'violation_place_freetext' => $case->violation_place_freetext,
            'violation_time' => $case->violation_time, 'violation_type' => $case->violation_type,
            'violation_type_id' => $case->violation_type_id, 'description' => $case->description,
            'alleged_responsible_employee_id' => $case->alleged_responsible_employee_id,
            'alleged_responsible_person_freetext' => $case->alleged_responsible_person_freetext,
            'witnesses' => $case->witnesses->map(fn ($witness) => [
                'name' => $witness->name, 'employee_id' => $witness->employee_id,
            ])->all(),
        ];
    }

    private function requireHr(User $actor): void
    {
        abort_unless($actor->is_active && in_array($actor->primary_role,
            [UserRole::CompanyAdmin, UserRole::HrStaff], true), 403);
    }
}
