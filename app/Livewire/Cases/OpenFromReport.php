<?php

namespace App\Livewire\Cases;

use App\Enums\UserRole;
use App\Models\DisciplinaryCase;
use App\Models\Location;
use App\Models\User;
use App\Models\ViolationReport;
use App\Models\ViolationType;
use App\Services\Employees\EmployeePicker;
use App\Services\Employees\ReportEmployeeQuickAdd;
use App\Services\Reports\ReportCaseOpening;
use App\Support\Access\CaseVisibility;
use App\Support\Access\ViolationReportVisibility;
use App\Support\Dates\ReportedDate;
use App\Support\Reports\UnknownAnswer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class OpenFromReport extends Component
{
    public int $reportId;

    public ?int $draftId = null;

    public array $people = [];

    public ?int $searchingRow = null;

    public array $peopleSearch = ['employees' => ''];

    public string $locationId = '';

    public string $place = '';

    public string $incidentDate = '';

    public string $incidentTime = '';

    public string $violationTypeId = '';

    public string $violationType = '';

    public string $description = '';

    public string $assignedHrUserId = '';

    public bool $includePriorRecord = false;

    public string $responsibleSearch = '';

    public string $responsibleEmployeeId = '';

    public string $responsibleName = '';

    public array $findings = [];

    public array $duplicateLinks = [];

    public ?int $addingRow = null;

    public string $newEmployeeName = '';

    public string $newEmployeePosition = '';

    public string $newEmployeeDepartment = '';

    public string $newEmployeeHireDate = '';

    public function mount(int $report, ReportCaseOpening $opening): void
    {
        Gate::authorize('create', DisciplinaryCase::class);
        $source = ViolationReportVisibility::inbox(auth()->user())->find($report);
        if ($source === null) {
            $draft = CaseVisibility::for(auth()->user())->where('violation_report_id', $report)
                ->where('status', 'draft')->firstOrFail();
            Gate::authorize('update', $draft);
            $this->draftId = $draft->id;
            $source = ViolationReport::query()->where('company_id', auth()->user()->company_id)->findOrFail($report);
        }
        $this->reportId = $source->id;
        $this->people = $opening->people($source, auth()->user());
        $this->locationId = (string) $source->location_id;
        $this->place = UnknownAnswer::matches((string) $source->place_freetext) ? '' : (string) $source->place_freetext;
        if ($source->occurred_at) {
            $date = CarbonImmutable::parse($source->occurred_at);
            $this->incidentDate = $date->format('Y-m-d');
            $this->incidentTime = $date->format('H:i');
        } elseif ($reportedDate = ReportedDate::parse((string) $source->occurred_at_text,
            CarbonImmutable::parse($source->created_at, 'Asia/Baghdad'))) {
            $this->incidentDate = $reportedDate->format('Y-m-d');
            $this->incidentTime = preg_match('/\d{1,2}:\d{2}/', (string) $source->occurred_at_text)
                ? $reportedDate->format('H:i') : '';
        }
        $this->violationTypeId = (string) $source->violation_type_id;
        $this->violationType = (string) $source->violation_type;
        $this->description = (string) $source->description;
        $this->responsibleEmployeeId = (string) $source->alleged_responsible_employee_id;
        $this->responsibleName = (string) ($source->allegedResponsibleEmployee?->name ?? $source->alleged_responsible_person_freetext);
        $this->assignedHrUserId = (string) auth()->id();
        if ($this->draftId !== null) {
            $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
            $this->locationId = (string) $draft->location_id;
            $this->place = (string) $draft->violation_place_freetext;
            $this->incidentDate = CarbonImmutable::parse($draft->violation_time)->format('Y-m-d');
            $this->incidentTime = $draft->violation_time_known
                ? CarbonImmutable::parse($draft->violation_time)->format('H:i') : '';
            $this->violationTypeId = (string) $draft->violation_type_id;
            $this->violationType = (string) $draft->violation_type;
            $this->description = (string) $draft->description;
            $this->responsibleEmployeeId = (string) $draft->alleged_responsible_employee_id;
            $this->responsibleName = (string) ($draft->allegedResponsibleEmployee?->name ?? $draft->alleged_responsible_person_freetext);
            $this->assignedHrUserId = (string) $draft->assigned_hr_user_id;
            $this->includePriorRecord = (bool) $draft->include_prior_record;
        }
    }

    public function selectPerson(int $index, int $id, EmployeePicker $picker): void
    {
        $this->source();
        abort_unless(isset($this->people[$index]), 404);
        $picker->employee(auth()->user(), (int) $this->source()->branch_id, $id);
        abort_if(($this->people[$index]['linked_id'] ?? null) !== null &&
            (int) $this->people[$index]['linked_id'] !== $id, 422);
        $this->people[$index]['selected_id'] = $id;
        $this->searchingRow = null;
        $this->peopleSearch['employees'] = '';
    }

    public function searchPerson(int $index): void
    {
        $this->source();
        abort_unless(isset($this->people[$index]), 404);
        $this->searchingRow = $index;
        $this->peopleSearch['employees'] = $this->people[$index]['name'];
    }

    public function startAddingPerson(int $index): void
    {
        $this->source();
        abort_unless(isset($this->people[$index]) && ($this->people[$index]['linked_id'] ?? null) === null, 404);
        $this->addingRow = $index;
        $this->newEmployeeName = $this->people[$index]['kind'] === 'free' ? $this->people[$index]['name'] : '';
        $this->newEmployeePosition = '';
        $this->newEmployeeDepartment = '';
        $this->newEmployeeHireDate = '';
    }

    public function addNewPerson(ReportEmployeeQuickAdd $quickAdd): void
    {
        $report = $this->source();
        abort_unless($this->draftId === null && $this->addingRow !== null && isset($this->people[$this->addingRow])
            && ($this->people[$this->addingRow]['linked_id'] ?? null) === null, 409);
        $data = $this->validate([
            'newEmployeeName' => ['required', 'string', 'max:255'],
            'newEmployeePosition' => ['required', 'string', 'max:255'],
            'newEmployeeDepartment' => ['required', 'string', 'max:255'],
            'newEmployeeHireDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $employee = $quickAdd->create($report, auth()->user(), $data['newEmployeeName'],
            $data['newEmployeePosition'], $data['newEmployeeDepartment'], $data['newEmployeeHireDate']);
        $this->people[$this->addingRow]['selected_id'] = $employee->id;
        $this->addingRow = null;
    }

    public function addPerson(string $kind, int $id, EmployeePicker $picker): void
    {
        abort_unless($kind === 'employees' && $this->searchingRow !== null, 403);
        $this->selectPerson($this->searchingRow, $id, $picker);
    }

    public function selectResponsible(int $id, EmployeePicker $picker): void
    {
        $employee = $picker->employee(auth()->user(), (int) $this->source()->branch_id, $id);
        $this->responsibleEmployeeId = (string) $id;
        $this->responsibleName = $employee->name;
        $this->responsibleSearch = '';
    }

    public function updatedResponsibleName(): void
    {
        $this->responsibleEmployeeId = '';
    }

    public function save(ReportCaseOpening $opening): void
    {
        Gate::authorize('create', DisciplinaryCase::class);
        abort_if($this->draftId !== null, 409);
        $draft = $opening->save($this->source(), auth()->user(), $this->people, $this->facts());
        $this->draftId = $draft->id;
        $this->dispatch('draft-saved');
    }

    public function open(ReportCaseOpening $opening): void
    {
        if ($this->draftId === null) {
            $this->save($opening);
        }
        $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
        Gate::authorize('update', $draft);
        $this->findings = $opening->findings($draft);
        if ($this->findings['duplicates'] !== [] || $this->findings['conflicts'] !== []) {
            return;
        }
        $case = $opening->open($draft, auth()->user());
        $this->redirect('/cases/'.$case->id, navigate: true);
    }

    public function confirmOpen(ReportCaseOpening $opening, ?int $duplicateId = null): void
    {
        abort_unless($this->draftId !== null && $this->findings !== [], 409);
        $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
        $case = $opening->open($draft, auth()->user(), $duplicateId, $this->duplicateLinks);
        $this->redirect('/cases/'.$case->id, navigate: true);
    }

    public function chooseDuplicate(int $memberId, int $otherId): void
    {
        abort_unless($this->draftId !== null, 409);
        $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
        $eligible = collect(app(ReportCaseOpening::class)->findings($draft)['duplicates'])
            ->contains(fn (array $row): bool => $row['member_id'] === $memberId && $row['id'] === $otherId);
        abort_unless($eligible, 422);
        if (($this->duplicateLinks[$memberId] ?? null) === $otherId) {
            unset($this->duplicateLinks[$memberId]);
        } else {
            $this->duplicateLinks[$memberId] = $otherId;
        }
    }

    public function render(EmployeePicker $picker): View
    {
        Gate::authorize('create', DisciplinaryCase::class);
        $report = $this->source()->load(['reporter', 'branch', 'location', 'employees', 'witnesses',
            'attachments', 'intakeQuestions', 'assistantSession', 'allegedResponsibleEmployee']);
        $draftNumbers = collect();
        if ($this->draftId !== null) {
            $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
            $draftNumbers = $draft->incident_id
                ? CaseVisibility::for(auth()->user())->where('incident_id', $draft->incident_id)->get(['id', 'case_number'])
                : collect([$draft]);
        }

        return view('livewire.cases.open-from-report', [
            'report' => $report,
            'draftNumbers' => $draftNumbers,
            'searchResults' => $this->searchingRow !== null ? $picker->search(auth()->user(), (int) $report->branch_id, $this->peopleSearch['employees']) : [],
            'responsibleResults' => $picker->search(auth()->user(), (int) $report->branch_id, $this->responsibleSearch),
            'locations' => Location::query()->where('branch_id', $report->branch_id)->where('is_active', true)->get(),
            'violationTypes' => ViolationType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'hrUsers' => User::query()->where('company_id', auth()->user()->company_id)->where('is_active', true)
                ->whereIn('primary_role', [UserRole::HrStaff->value, UserRole::CompanyAdmin->value])->get(),
        ])->layout('layouts.workspace');
    }

    private function facts(): array
    {
        $data = $this->validate([
            'locationId' => ['nullable', 'integer'], 'place' => ['nullable', 'string', 'max:255'],
            'incidentDate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'incidentTime' => ['nullable', 'date_format:H:i'],
            'violationTypeId' => ['nullable', 'integer'], 'violationType' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'], 'assignedHrUserId' => ['required', 'integer'],
            'responsibleName' => ['nullable', 'string', 'max:255'], 'responsibleEmployeeId' => ['nullable', 'integer'],
        ]);
        if ($this->locationId === '' && trim($this->place) === '') {
            throw ValidationException::withMessages(['place' => __('Choose a location or enter the place before opening a case.')]);
        }
        if ($this->violationTypeId === '' && trim($this->violationType) === '') {
            throw ValidationException::withMessages(['violationType' => __('Choose a violation type or describe another')]);
        }
        $when = $data['incidentDate'].' '.($data['incidentTime'] ?: '00:00');
        if (CarbonImmutable::parse($when, 'Asia/Baghdad')->isFuture()) {
            throw ValidationException::withMessages(['incidentTime' => __('The incident date and time must not be in the future.')]);
        }
        if ($data['responsibleEmployeeId'] !== '') {
            app(EmployeePicker::class)->employee(auth()->user(), (int) $this->source()->branch_id, (int) $data['responsibleEmployeeId']);
        }

        return [
            'branch_id' => $this->source()->branch_id,
            'location_id' => $data['locationId'] ?: null,
            'violation_place_freetext' => $data['place'] ?: null,
            'violation_time' => $when,
            'violation_time_known' => $data['incidentTime'] !== '',
            'violation_type_id' => $data['violationTypeId'] ?: null,
            'violation_type' => $data['violationType'],
            'description' => $data['description'],
            'assigned_hr_user_id' => (int) $data['assignedHrUserId'],
            'include_prior_record' => $this->includePriorRecord,
            'alleged_responsible_employee_id' => $data['responsibleEmployeeId'] ?: null,
            'alleged_responsible_person_freetext' => $data['responsibleEmployeeId'] ? null : ($data['responsibleName'] ?: null),
        ];
    }

    private function source(): ViolationReport
    {
        if ($this->draftId !== null) {
            $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
            Gate::authorize('update', $draft);
            abort_unless((int) $draft->violation_report_id === $this->reportId, 404);

            return ViolationReport::query()->where('company_id', auth()->user()->company_id)->findOrFail($this->reportId);
        }

        return ViolationReportVisibility::inbox(auth()->user())->findOrFail($this->reportId);
    }
}
