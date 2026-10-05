<?php

namespace App\Livewire\Cases;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use App\Services\Cases\CaseDrafts;
use App\Services\Cases\CaseInsights;
use App\Services\Cases\CaseOpening;
use App\Services\Reports\ReportService;
use App\Support\Access\CaseVisibility;
use App\Support\Access\ViolationReportVisibility;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class CreateCase extends Component
{
    public string $branchId = '';

    public string $employeeId = '';

    public string $employeeSearch = '';

    public string $locationId = '';

    public string $place = '';

    public string $violationTime = '';

    public string $violationType = '';

    public string $description = '';

    public string $responsibleEmployeeId = '';

    public string $responsibleSearch = '';

    public string $responsibleName = '';

    public string $witnessNames = '';

    public bool $includePriorRecord = false;

    public string $assignedHrUserId = '';

    public ?int $draftId = null;

    public ?int $reportId = null;

    public bool $checkedDuplicates = false;

    public array $duplicates = [];

    public string $duplicateCaseId = '';

    public function mount(): void
    {
        $this->authorizeCreate();
        if ($id = request()->query('report')) {
            $report = ViolationReportVisibility::inbox(auth()->user())->findOrFail($id);
            $this->reportId = $report->id;
            $this->branchId = (string) $report->branch_id;
            $this->employeeId = (string) $report->employee_id;
            $this->locationId = (string) $report->location_id;
            $this->place = (string) $report->place_freetext;
            $this->violationTime = Carbon::parse($report->occurred_at)->format('Y-m-d\TH:i');
            $this->violationType = $report->violation_type;
            $this->description = $report->description;
            $this->responsibleEmployeeId = (string) $report->alleged_responsible_employee_id;
            $this->responsibleName = (string) $report->alleged_responsible_person_freetext;
            $this->witnessNames = $report->witnesses->pluck('name')->implode(', ');
        }
    }

    public function save(CaseDrafts $drafts): void
    {
        $this->authorizeCreate();
        $data = $this->validatedFacts();
        $draft = $this->draftId ? $drafts->update(CaseVisibility::for(auth()->user())->findOrFail($this->draftId), $data, auth()->user())
            : ($this->reportId ? app(ReportService::class)->convert(
                ViolationReportVisibility::inbox(auth()->user())->findOrFail($this->reportId), auth()->user(), null, $data)
                : $drafts->create($data, auth()->user()));
        $this->draftId = $draft->id;
        $this->checkedDuplicates = false;
        $this->dispatch('draft-saved');
    }

    public function checkDuplicates(CaseDrafts $drafts, CaseInsights $insights): void
    {
        $this->authorizeCreate();
        if ($this->draftId === null) {
            $this->save($drafts);
        }
        $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
        $this->duplicates = $insights->duplicateCandidates($draft)['cases']->map(fn ($case) => ['id' => $case->id, 'number' => $case->case_number])->all();
        $this->checkedDuplicates = true;
    }

    public function open(CaseOpening $opening, CaseInsights $insights): void
    {
        $this->authorizeCreate();
        abort_unless($this->checkedDuplicates && $this->draftId !== null, 409);
        $draft = CaseVisibility::for(auth()->user())->findOrFail($this->draftId);
        Gate::authorize('update', $draft);
        $eligible = $insights->duplicateCandidates($draft)['cases']->pluck('id');
        if ($this->duplicateCaseId !== '') {
            abort_unless($eligible->contains((int) $this->duplicateCaseId), 422);
        }
        $case = $opening->open($draft, auth()->user());
        if ($this->duplicateCaseId !== '') {
            $other = CaseVisibility::for(auth()->user())->findOrFail($this->duplicateCaseId);
            $insights->linkDuplicate($case, $other);
        }
        $this->redirect('/cases/'.$case->id, navigate: true);
    }

    public function render(): View
    {
        $this->authorizeCreate();

        return view('livewire.cases.create-case', [
            'branches' => Branch::query()->where('company_id', auth()->user()->company_id)->where('is_active', true)->get(),
            'employees' => $this->branchId ? Employee::query()->where('branch_id', $this->branchId)->where('is_active', true)
                ->when($this->employeeSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->employeeSearch.'%'))->get() : collect(),
            'responsibleEmployees' => $this->branchId ? Employee::query()->where('branch_id', $this->branchId)->where('is_active', true)
                ->when($this->responsibleSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->responsibleSearch.'%'))->get() : collect(),
            'locations' => $this->branchId ? Location::query()->where('branch_id', $this->branchId)->where('is_active', true)->get() : collect(),
            'hrUsers' => User::query()->where('company_id', auth()->user()->company_id)->where('is_active', true)->whereIn('primary_role', [UserRole::HrStaff->value, UserRole::CompanyAdmin->value])->get(),
        ])->layout('layouts.workspace');
    }

    private function validatedFacts(): array
    {
        $data = $this->validate([
            'branchId' => ['required', 'integer'], 'employeeId' => ['required', 'integer'],
            'locationId' => ['nullable', 'integer'], 'place' => ['nullable', 'string', 'max:255'],
            'violationTime' => ['required', 'date'], 'violationType' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'], 'responsibleEmployeeId' => ['nullable', 'integer'],
            'responsibleName' => ['nullable', 'string', 'max:255'], 'witnessNames' => ['nullable', 'string'],
            'assignedHrUserId' => ['nullable', 'integer'],
        ]);
        if (blank($this->locationId) && blank($this->place)) {
            $this->addError('place', 'اختر موقعاً أو اكتب المكان.');
            throw ValidationException::withMessages(['place' => 'اختر موقعاً أو اكتب المكان.']);
        }

        return [
            'branch_id' => $data['branchId'], 'employee_id' => $data['employeeId'],
            'location_id' => $data['locationId'] ?: null, 'violation_place_freetext' => $data['place'] ?: null,
            'violation_time' => $data['violationTime'], 'violation_type' => $data['violationType'],
            'description' => $data['description'], 'alleged_responsible_employee_id' => $data['responsibleEmployeeId'] ?: null,
            'alleged_responsible_person_freetext' => $data['responsibleName'] ?: null,
            'assigned_hr_user_id' => $data['assignedHrUserId'] ?: auth()->id(),
            'include_prior_record' => $this->includePriorRecord,
            'witnesses' => array_map(fn ($name) => ['name' => trim($name)], array_filter(explode(',', $data['witnessNames'] ?? ''))),
        ];
    }

    private function authorizeCreate(): void
    {
        Gate::authorize('create', DisciplinaryCase::class);
    }
}
