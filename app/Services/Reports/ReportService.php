<?php

namespace App\Services\Reports;

use App\Enums\AttachmentKind;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Models\ViolationReport;
use App\Services\Attachments\AttachmentService;
use App\Services\AuditLogger;
use App\Services\CaseEventLogger;
use App\Services\Cases\CaseDrafts;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseViolationTypes;
use App\Services\Cases\IncidentService;
use App\Services\Employees\EmployeePicker;
use App\Services\Intake\IntakeQuestionService;
use App\Support\Access\ViolationReportVisibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public function submit(array $data, User $actor): ViolationReport
    {
        abort_unless($actor->is_active && $actor->company?->is_active
            && in_array($actor->primary_role, [UserRole::Reporter, UserRole::DeptManager], true), 403);
        $branchId = (int) $data['branch_id'];
        abort_unless(DB::table('branch_user')->where('company_id', $actor->company_id)->where('branch_id', $branchId)->where('user_id', $actor->id)->exists(), 403);
        if (! empty($data['location_id'])) {
            Location::query()->where('branch_id', $branchId)->findOrFail($data['location_id']);
        }
        $extraIds = array_map('intval', $data['employee_ids'] ?? []);
        if (count($extraIds) !== count(array_unique($extraIds))) {
            throw ValidationException::withMessages(['employee_ids' => __('messages.incident.unique_employees_required')]);
        }
        $employeeIds = array_values(array_unique(array_map('intval', array_merge(
            array_filter([$data['employee_id'] ?? null]), $extraIds))));
        foreach ($employeeIds as $id) {
            Employee::query()->where('company_id', $actor->company_id)->where('branch_id', $branchId)->where('is_active', true)->findOrFail($id);
        }
        $data['witnesses'] = app(EmployeePicker::class)->selections($actor, $branchId, $data['witnesses'] ?? [], true);
        $data['free_employee_names'] = array_values(array_map(function ($name): string {
            if (! is_string($name) || trim($name) === '' || mb_strlen(trim($name)) > 255) {
                throw ValidationException::withMessages(['free_employee_names' => __('Choose a person or enter a name')]);
            }

            return trim($name);
        }, $data['free_employee_names'] ?? []));
        if ($employeeIds === [] && $data['free_employee_names'] === [] && ! ($data['employees_unknown'] ?? false)) {
            throw ValidationException::withMessages(['people.employees' => __('Name at least one person.')]);
        }
        if (blank($data['description'] ?? null)) {
            throw ValidationException::withMessages(['description' => __('Describe what happened in a few words.')]);
        }
        if (blank($data['place_freetext'] ?? null) && empty($data['location_id'])) {
            throw ValidationException::withMessages(['place' => __('Enter where it happened or choose I do not know.')]);
        }
        if (blank($data['occurred_at'] ?? null) && blank($data['occurred_at_text'] ?? null)) {
            throw ValidationException::withMessages(['occurredAtText' => __('Enter when it happened or choose I do not know.')]);
        }
        if ($data['witnesses'] === [] && ! ($data['witnesses_none'] ?? false) && ! ($data['witnesses_unknown'] ?? false)) {
            throw ValidationException::withMessages(['witnessesNone' => __('Name a witness or choose none.')]);
        }
        $data['employees_unknown'] = $employeeIds === [] && $data['free_employee_names'] === [] && ($data['employees_unknown'] ?? false);
        if ($data['witnesses'] !== []) {
            $data['witnesses_none'] = false;
            $data['witnesses_unknown'] = false;
        }
        if (($data['witnesses_none'] ?? false) && ($data['witnesses_unknown'] ?? false)) {
            throw ValidationException::withMessages(['witnessesNone' => __('Choose one witness answer.')]);
        }
        $data['employee_id'] = $employeeIds[0] ?? null;
        if (! empty($data['alleged_responsible_employee_id'])) {
            Employee::query()->where('company_id', $actor->company_id)->where('branch_id', $branchId)
                ->findOrFail($data['alleged_responsible_employee_id']);
        }
        $data = array_replace($data, app(CaseViolationTypes::class)->selected(
            isset($data['violation_type_id']) ? (int) $data['violation_type_id'] : null,
            $data['violation_type'] ?? null, (int) $actor->company_id));
        $session = null;
        if (! empty($data['assistant_session_id'])) {
            $session = app(ReportAssistant::class)->owned((int) $data['assistant_session_id'], $actor);
            abort_unless((int) ($session->facts['branch_id'] ?? 0) === $branchId
                && in_array($session->status, ['ready', 'manual', 'fallback'], true), 409);
        }
        $data['summary'] = $session && filled($session->facts['summary'] ?? null)
            && ($data['assistant_summary'] ?? null) === $session->facts['summary']
            && ($session->facts['description'] ?? null) === $data['description']
            ? $session->facts['summary']
            : ($session ? trans('report_assistant.summary_prefix', locale: $session->language)
                : __('According to the reporter: ')).mb_substr($data['description'], 0, 600);

        return DB::transaction(function () use ($data, $actor, $branchId, $employeeIds, $session): ViolationReport {
            $report = ViolationReport::create(array_intersect_key($data, array_flip([
                'employee_id', 'employees_unknown', 'location_id', 'place_freetext', 'occurred_at', 'occurred_at_text',
                'witnesses_none', 'witnesses_unknown', 'summary', 'how_started', 'injuries_damage',
                'violation_type', 'violation_type_id',
                'alleged_responsible_employee_id', 'alleged_responsible_person_freetext', 'description', 'free_employee_names',
            ])) + [
                'company_id' => $actor->company_id, 'branch_id' => $branchId,
                'reporter_user_id' => $actor->id, 'source' => ReportSource::Reporter,
                'status' => ReportStatus::Received,
            ]);
            foreach ($employeeIds as $employeeId) {
                $report->employees()->attach($employeeId, ['company_id' => $report->company_id]);
            }
            foreach ($data['witnesses'] ?? [] as $witness) {
                $report->witnesses()->create(['company_id' => $report->company_id, 'name' => $witness['name'], 'employee_id' => $witness['employee_id'] ?? null]);
            }
            foreach ($data['attachments'] ?? [] as $file) {
                app(AttachmentService::class)->store($report, AttachmentKind::ReportEvidence, $file, $actor);
            }
            if ($session !== null) {
                $session->update(['violation_report_id' => $report->id, 'status' => 'sent',
                    'confirmed_fields' => [
                        'employee_ids' => $employeeIds, 'free_employee_names' => $data['free_employee_names'],
                        'employees_unknown' => $data['employees_unknown'], 'place' => $data['place_freetext'] ?? null,
                        'location_id' => $data['location_id'] ?? null, 'occurred_at' => $data['occurred_at'] ?? null,
                        'occurred_at_text' => $data['occurred_at_text'] ?? null,
                        'witnesses' => $data['witnesses'], 'witnesses_none' => $data['witnesses_none'] ?? false,
                        'witnesses_unknown' => $data['witnesses_unknown'] ?? false,
                        'description' => $data['description'], 'violation_type' => $data['violation_type'],
                        'violation_type_id' => $data['violation_type_id'],
                        'alleged_responsible_employee_id' => $data['alleged_responsible_employee_id'] ?? null,
                        'alleged_responsible_person_freetext' => $data['alleged_responsible_person_freetext'] ?? null,
                        'how_started' => $data['how_started'] ?? null, 'injuries_damage' => $data['injuries_damage'] ?? null,
                    ]]);
            }
            if ($data['intake_check'] ?? null) {
                app(IntakeQuestionService::class)->attach($data['intake_check'], $report, $data['intake_answers'] ?? [], $actor);
            }
            app(AuditLogger::class)->log('report.submitted', $report, actor: $actor);

            return $report;
        });
    }

    public function dismiss(ViolationReport $report, string $reason, User $actor): void
    {
        $this->authorizeInbox($report, $actor);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => __('messages.report.dismissal_reason_required')]);
        }
        DB::transaction(function () use ($report, $reason, $actor): void {
            $report = ViolationReport::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless(in_array($report->status, [ReportStatus::Received, ReportStatus::UnderReview], true), 409);
            $report->update(['status' => ReportStatus::Dismissed]);
            app(AuditLogger::class)->log('report.dismissed', $report, ['reason' => $reason], $actor);
        });
    }

    public function convert(ViolationReport $report, User $actor, ?DisciplinaryCase $existing = null, array $facts = []): DisciplinaryCase
    {
        $this->authorizeInbox($report, $actor);
        abort_if($existing !== null && (($report->free_employee_names ?? []) !== [] || $this->reportEmployeeIds($report) === []), 422);

        return DB::transaction(function () use ($report, $actor, $existing, $facts): DisciplinaryCase {
            $report = ViolationReport::query()->lockForUpdate()->findOrFail($report->id);
            abort_unless(in_array($report->status, [ReportStatus::Received, ReportStatus::UnderReview], true), 409);
            if ($existing !== null) {
                abort_if($report->employees()->count() > 1, 422);
                Gate::forUser($actor)->authorize('update', $existing);
                abort_unless($existing->company_id === $report->company_id, 404);
                abort_unless(app(CaseInsights::class)->duplicateCandidates($report)['cases']->contains('id', $existing->id), 422);
                $case = $existing;
                app(CaseEventLogger::class)->log($case, 'duplicate_report_linked', ['report_id' => $report->id], actor: $actor);
            } else {
                $data = [
                    'branch_id' => $report->branch_id, 'employee_id' => $report->employee_id,
                    'location_id' => $report->location_id, 'violation_place_freetext' => $report->place_freetext,
                    'violation_time' => $report->occurred_at, 'violation_type' => $report->violation_type,
                    'violation_type_id' => $report->violation_type_id,
                    'description' => $report->description,
                    'alleged_responsible_employee_id' => $report->alleged_responsible_employee_id,
                    'alleged_responsible_person_freetext' => $report->alleged_responsible_person_freetext,
                    'witnesses' => $report->witnesses->map(fn ($w) => ['name' => $w->name, 'employee_id' => $w->employee_id])->all(),
                ];
                if ($report->occurred_at === null && empty($facts['violation_time'])) {
                    throw ValidationException::withMessages(['violationTime' => __('Enter an incident date before opening a case.')]);
                }
                // The explicit mapping lets two typed names resolve to one employee without dropping either name.
                $reportEmployeeIds = $this->reportEmployeeIds($report);
                $chosen = $facts['employee_ids'] ?? (! empty($facts['employee_id']) ? [$facts['employee_id']] : $reportEmployeeIds);
                $selected = array_values(array_unique(array_map('intval', $chosen)));
                $free = $report->free_employee_names ?? [];
                $resolved = array_map('intval', $facts['resolved_free_employee_ids'] ?? []);
                $freeResolved = array_key_exists('resolved_free_employee_ids', $facts)
                    ? count($resolved) === count($free) && array_diff($resolved, $selected) === []
                    : count($selected) >= count($reportEmployeeIds) + count($free);
                if (array_diff($reportEmployeeIds, $selected) !== [] || ! $freeResolved) {
                    throw ValidationException::withMessages(['employeeId' => __('Add employees to the list before opening a case')]);
                }
                foreach ($selected as $id) {
                    app(EmployeePicker::class)->employee($actor, (int) $report->branch_id, $id);
                }
                unset($facts['employee_ids'], $facts['resolved_free_employee_ids']);
                if (count($selected) === 1) {
                    $facts['employee_id'] = $selected[0];
                }
                if (count($selected) > 1) {
                    $incident = app(IncidentService::class)->createForEmployees($selected, array_replace($data, $facts), $actor, $report);
                    $case = $incident->cases()->orderBy('id')->firstOrFail();
                } else {
                    $case = app(CaseDrafts::class)->create(array_replace($data, $facts), $actor, $report);
                }
            }
            $report->update(['status' => ReportStatus::ConvertedToCase, 'case_id' => $case->id]);
            app(IntakeQuestionService::class)->carryToCase($report, $case);
            app(AuditLogger::class)->log($existing ? 'report.duplicate_linked' : 'report.converted', $report, ['case_id' => $case->id], $actor);

            return $case;
        });
    }

    /** @return list<int> */
    private function reportEmployeeIds(ViolationReport $report): array
    {
        $ids = $report->employees()->pluck('employees.id')->map(fn ($id): int => (int) $id)->all();

        return $ids === [] && $report->employee_id !== null ? [(int) $report->employee_id] : $ids;
    }

    private function authorizeInbox(ViolationReport $report, User $actor): void
    {
        abort_unless(ViolationReportVisibility::inbox($actor)->whereKey($report->id)->exists(), 403);
    }
}
