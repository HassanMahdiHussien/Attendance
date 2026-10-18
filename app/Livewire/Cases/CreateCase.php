<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Livewire\Concerns\ChecksDescription;
use App\Livewire\Concerns\PicksPeople;
use App\Models\Branch;
use App\Models\DisciplinaryCase;
use App\Models\Incident;
use App\Models\IntakeQuestion;
use App\Models\Location;
use App\Models\User;
use App\Models\ViolationType;
use App\Services\Cases\CaseDrafts;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseOpening;
use App\Services\Cases\IncidentService;
use App\Services\EmailIntake\EmailDraftService;
use App\Services\Employees\EmployeePicker;
use App\Services\Intake\IntakeQuestionService;
use App\Services\Reports\ReportService;
use App\Support\Access\CaseVisibility;
use App\Support\Access\EmailDraftVisibility;
use App\Support\Access\ViolationReportVisibility;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class CreateCase extends Component
{
    use ChecksDescription;
    use PicksPeople;

    public string $branchId = '';

    public string $employeeId = '';

    public array $employeeIds = [];

    public ?int $incidentId = null;

    public bool $editingIncident = false;

    public string $locationId = '';

    public string $place = '';

    public string $violationTime = '';

    public string $violationType = '';

    public string $violationTypeId = '';

    public string $description = '';

    public array $existingIntakeAnswers = [];

    public bool $includePriorRecord = false;

    public string $assignedHrUserId = '';

    public ?int $draftId = null;

    public ?int $reportId = null;

    public ?int $emailId = null;

    public bool $checkedDuplicates = false;

    public array $duplicates = [];

    public string $duplicateCaseId = '';

    public function mount(): void
    {
        $this->authorizeCreate();
        if ($id = request()->query('draft') ?? request()->query('incident_case')) {
            $draft = CaseVisibility::for(auth()->user())->findOrFail($id);
            Gate::authorize('update', $draft);
            $this->editingIncident = request()->has('incident_case');
            abort_unless($this->editingIncident
                ? $draft->incident_id !== null && in_array($draft->status, [CaseStatus::Draft, CaseStatus::Open], true)
                    && $draft->defense_letter_issued_at === null && ! $draft->documents()->exists()
                : $draft->status === CaseStatus::Draft, 404);
            $this->draftId = $draft->id;
            $this->incidentId = $draft->incident_id;
            $this->branchId = (string) $draft->branch_id;
            $this->employeeId = (string) $draft->employee_id;
            $this->people['employees'] = [['employee_id' => $draft->employee_id, 'name' => $draft->employee->name]];
            $this->locationId = (string) $draft->location_id;
            $this->place = (string) $draft->violation_place_freetext;
            $this->violationTime = Carbon::parse($draft->violation_time)->format('Y-m-d\TH:i');
            $this->violationType = (string) $draft->violation_type;
            $this->violationTypeId = (string) $draft->violation_type_id;
            $this->description = (string) $draft->description;
            $this->people['responsible'] = $draft->alleged_responsible_employee_id
                ? [['employee_id' => $draft->alleged_responsible_employee_id, 'name' => $draft->allegedResponsibleEmployee->name]]
                : ($draft->alleged_responsible_person_freetext ? [['employee_id' => null, 'name' => $draft->alleged_responsible_person_freetext]] : []);
            $this->assignedHrUserId = (string) $draft->assigned_hr_user_id;
            $this->people['witnesses'] = $draft->witnesses->map(fn ($w) => ['employee_id' => $w->employee_id, 'name' => $w->name])->all();
            $this->includePriorRecord = (bool) $draft->include_prior_record;

            return;
        }
        if ($id = request()->query('email')) {
            $email = EmailDraftVisibility::inbox(auth()->user())->findOrFail($id);
            $this->emailId = $email->id;
            $this->branchId = (string) $email->branch_id;

            return;
        }
        if ($id = request()->query('report')) {
            $report = ViolationReportVisibility::inbox(auth()->user())->findOrFail($id);
            $this->reportId = $report->id;
            $this->branchId = (string) $report->branch_id;
            $this->employeeId = (string) $report->employee_id;
            $this->employeeIds = $report->employees()->where('employees.id', '!=', $report->employee_id)
                ->pluck('employees.id')->map(fn ($id) => (string) $id)->all();
            $this->people['employees'] = $report->employees->sortBy(fn ($e) => $e->id === $report->employee_id ? 0 : 1)
                ->map(fn ($e) => ['employee_id' => $e->id, 'name' => $e->name])->values()->all();
            $this->locationId = (string) $report->location_id;
            $this->place = (string) $report->place_freetext;
            $this->violationTime = Carbon::parse($report->occurred_at)->format('Y-m-d\TH:i');
            $this->violationType = $report->violation_type;
            $this->violationTypeId = (string) $report->violation_type_id;
            $this->description = $report->description;
            $this->people['responsible'] = $report->alleged_responsible_employee_id
                ? [['employee_id' => $report->alleged_responsible_employee_id, 'name' => $report->allegedResponsibleEmployee->name]]
                : ($report->alleged_responsible_person_freetext ? [['employee_id' => null, 'name' => $report->alleged_responsible_person_freetext]] : []);
            $this->people['witnesses'] = $report->witnesses->map(fn ($w) => ['employee_id' => $w->employee_id, 'name' => $w->name])->all();
        }
    }

    public function save(CaseDrafts $drafts, IncidentService $incidents): void
    {
        $this->authorizeCreate();
        $data = $this->validatedFacts();
        foreach ($this->existingIntakeAnswers as $questionId => $answer) {
            if (blank($answer)) {
                continue;
            }
            $question = IntakeQuestion::query()->findOrFail($questionId);
            abort_unless(($this->draftId && $question->case_id === $this->draftId)
                || ($this->reportId && $question->violation_report_id === $this->reportId)
                || ($this->emailId && $question->email_draft_id === $this->emailId), 403);
            app(IntakeQuestionService::class)->answer($question, (string) $answer, auth()->user());
        }
        $this->existingIntakeAnswers = [];
        $check = $this->intakeCheck();
        if ($check && in_array($check->status, ['ready', 'checklist'], true)) {
            $data['intake_check'] = $check;
            $data['intake_answers'] = $this->intakeAnswers;
        }
        $selectedIds = array_values(array_unique(array_map('intval', array_merge([$this->employeeId], $this->employeeIds))));
        if ($this->incidentId && $this->draftId) {
            $incidents->updateSharedFacts(Incident::query()->findOrFail($this->incidentId), $data, auth()->user());
            $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
        } elseif (! $this->draftId && ! $this->reportId && ! $this->emailId && count($selectedIds) > 1) {
            $incident = $incidents->createForEmployees($selectedIds, $data, auth()->user());
            $this->incidentId = $incident->id;
            $draft = $incident->cases()->orderBy('id')->firstOrFail();
        } else {
            $draft = $this->draftId ? $drafts->update(CaseVisibility::for(auth()->user())->findOrFail($this->draftId), $data, auth()->user())
            : ($this->emailId ? app(EmailDraftService::class)->convert(
                EmailDraftVisibility::inbox(auth()->user())->findOrFail($this->emailId), auth()->user(), $data)
                : ($this->reportId ? app(ReportService::class)->convert(
                    ViolationReportVisibility::inbox(auth()->user())->findOrFail($this->reportId), auth()->user(), null, $data)
                : $drafts->create($data, auth()->user())));
        }
        $this->draftId = $draft->id;
        $this->incidentId = $draft->incident_id;
        if ($this->incidentId !== null) {
            $this->people['employees'] = array_slice($this->people['employees'], 0, 1);
        }
        $this->intakeCheckId = null;
        $this->intakeAnswers = [];
        $this->emailId = null;
        $this->reportId = null;
        $this->checkedDuplicates = false;
        $this->dispatch('draft-saved');
    }

    public function checkDuplicates(CaseDrafts $drafts, CaseInsights $insights, IncidentService $incidents): void
    {
        $this->authorizeCreate();
        abort_if($this->editingIncident, 409);
        if ($this->draftId === null) {
            $this->save($drafts, $incidents);
        }
        $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
        $draftsToCheck = $draft->incident_id ? Incident::query()->findOrFail($draft->incident_id)
            ->cases()->where('status', CaseStatus::Draft->value)->get() : collect([$draft]);
        $this->duplicates = $draftsToCheck->flatMap(fn ($member) => $insights->duplicateCandidates($member)['cases']
            ->map(fn ($case) => ['id' => $case->id, 'number' => $case->case_number,
                'employee' => $member->employee?->name]))->all();
        $this->checkedDuplicates = true;
    }

    public function open(CaseOpening $opening, CaseInsights $insights, IncidentService $incidents): void
    {
        $this->authorizeCreate();
        abort_if($this->editingIncident, 409);
        abort_unless($this->checkedDuplicates && $this->draftId !== null, 409);
        $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
        Gate::authorize('update', $draft);
        $eligible = $insights->duplicateCandidates($draft)['cases']->pluck('id');
        if ($this->duplicateCaseId !== '') {
            abort_unless($eligible->contains((int) $this->duplicateCaseId), 422);
        }
        $case = $draft->incident_id
            ? $incidents->openDrafts(Incident::query()->findOrFail($draft->incident_id), auth()->user())
            : $opening->open($draft, auth()->user());
        if ($this->duplicateCaseId !== '') {
            $other = CaseVisibility::for(auth()->user())->findOrFail($this->duplicateCaseId);
            $insights->linkDuplicate($case, $other);
        }
        $this->redirect('/cases/'.$case->id, navigate: true);
    }

    public function render(EmployeePicker $picker): View
    {
        $this->authorizeCreate();

        return view('livewire.cases.create-case', [
            'email' => $this->emailId ? EmailDraftVisibility::inbox(auth()->user())->with('attachments')->findOrFail($this->emailId) : null,
            'sourceReport' => $this->reportId ? ViolationReportVisibility::inbox(auth()->user())->findOrFail($this->reportId) : null,
            'branches' => Branch::query()->where('company_id', auth()->user()->company_id)->where('is_active', true)->get(),
            'peopleResults' => $this->peopleResults($picker),
            'locations' => $this->branchId ? Location::query()->where('branch_id', $this->branchId)->where('is_active', true)->get() : collect(),
            'violationTypes' => ViolationType::query()->where(fn ($query) => $query->where('is_active', true)
                ->when($this->violationTypeId !== '', fn ($query) => $query->orWhereKey($this->violationTypeId)))
                ->orderBy('sort_order')->get(),
            'hrUsers' => User::query()->where('company_id', auth()->user()->company_id)->where('is_active', true)->whereIn('primary_role', [UserRole::HrStaff->value, UserRole::CompanyAdmin->value])->get(),
            'intakeCheck' => $this->intakeCheck(),
            'existingIntakeQuestions' => $this->draftId ? DisciplinaryCase::query()->findOrFail($this->draftId)->intakeQuestions()->orderBy('id')->get()
                : ($this->reportId ? ViolationReportVisibility::inbox(auth()->user())->findOrFail($this->reportId)->intakeQuestions()->orderBy('id')->get()
                : ($this->emailId ? EmailDraftVisibility::inbox(auth()->user())->findOrFail($this->emailId)->intakeQuestions()->orderBy('id')->get() : collect())),
        ])->layout('layouts.workspace');
    }

    private function validatedFacts(): array
    {
        $data = $this->validate([
            'branchId' => ['required', 'integer'],
            'locationId' => ['nullable', 'integer'], 'place' => ['nullable', 'string', 'max:255'],
            'violationTime' => ['required', 'date', 'before_or_equal:now'], 'violationType' => ['nullable', 'string', 'max:255'],
            'violationTypeId' => ['nullable', 'integer'],
            'description' => ['nullable', 'string'],
            'assignedHrUserId' => ['nullable', 'integer'],
        ]);
        if (blank($this->locationId) && blank($this->place)) {
            $this->addError('place', __('messages.report.location_or_place_required'));
            throw ValidationException::withMessages(['place' => __('messages.report.location_or_place_required')]);
        }
        if (blank($data['violationTypeId']) && blank($data['violationType'])) {
            throw ValidationException::withMessages(['violationType' => __('Choose a violation type or describe another')]);
        }
        $employees = $this->pickedPeople('employees', false);
        if ($employees === []) {
            throw ValidationException::withMessages(['employeeId' => __('Choose an employee')]);
        }
        abort_if(! $this->allowsMultipleEmployees() && count($employees) > 1, 422);
        $responsible = $this->pickedPeople('responsible');
        $this->employeeId = (string) $employees[0]['employee_id'];
        $this->employeeIds = array_map(fn ($item) => (string) $item['employee_id'], array_slice($employees, 1));

        return [
            'branch_id' => $data['branchId'], 'employee_id' => $this->employeeId,
            'employee_ids' => array_map(fn ($item) => $item['employee_id'], $employees),
            'location_id' => $data['locationId'] ?: null, 'violation_place_freetext' => $data['place'] ?: null,
            'violation_time' => $data['violationTime'], 'violation_type' => $data['violationType'],
            'violation_type_id' => $data['violationTypeId'] ?: null,
            'description' => $data['description'], 'alleged_responsible_employee_id' => $responsible[0]['employee_id'] ?? null,
            'alleged_responsible_person_freetext' => ($responsible[0]['employee_id'] ?? null) ? null : ($responsible[0]['name'] ?? null),
            'assigned_hr_user_id' => $data['assignedHrUserId'] ?: auth()->id(),
            'include_prior_record' => $this->includePriorRecord,
            'witnesses' => $this->pickedPeople('witnesses'),
        ];
    }

    private function authorizeCreate(): void
    {
        Gate::authorize('create', DisciplinaryCase::class);
    }

    protected function intakeFields(): array
    {
        return ['description' => $this->description,
            'violation_type' => $this->violationTypeId !== '' ? (ViolationType::query()->find($this->violationTypeId)?->name() ?? '') : $this->violationType,
            'place' => $this->locationId !== '' ? (Location::query()->find($this->locationId)?->name ?? '') : $this->place,
            'time' => $this->violationTime];
    }

    protected function authorizeIntakeCheck(): void
    {
        $this->authorizeCreate();
    }

    protected function allowsMultipleEmployees(): bool
    {
        return $this->draftId === null && $this->emailId === null && ! $this->editingIncident;
    }
}
