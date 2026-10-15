<?php

namespace App\Livewire\Reports;

use App\Enums\UserRole;
use App\Livewire\Concerns\ChecksDescription;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Location;
use App\Models\ViolationType;
use App\Services\Reports\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

class SubmitReport extends Component
{
    use ChecksDescription;
    use WithFileUploads;

    public string $branchId = '';

    public string $employeeId = '';

    public array $employeeIds = [];

    public string $employeeSearch = '';

    public string $locationId = '';

    public string $place = '';

    public string $occurredAt = '';

    public string $violationType = '';

    public string $violationTypeId = '';

    public string $responsibleEmployeeId = '';

    public string $responsibleSearch = '';

    public string $responsibleName = '';

    public string $witnessNames = '';

    public string $description = '';

    public array $attachments = [];

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()->primary_role, [UserRole::Reporter, UserRole::DeptManager], true), 403);
    }

    public function submit(ReportService $reports): void
    {
        $this->mount();
        $data = $this->validate([
            'branchId' => ['required', 'integer'], 'employeeId' => ['nullable', 'integer'],
            'employeeIds' => ['array'], 'employeeIds.*' => ['integer', 'distinct'],
            'locationId' => ['nullable', 'integer'], 'place' => ['nullable', 'string', 'max:255'],
            'occurredAt' => ['required', 'date'], 'violationType' => ['nullable', 'string', 'max:255'],
            'violationTypeId' => ['nullable', 'integer'],
            'responsibleEmployeeId' => ['nullable', 'integer'], 'responsibleName' => ['nullable', 'string', 'max:255'],
            'witnessNames' => ['nullable', 'string'], 'description' => ['required', 'string'],
            'attachments' => ['array'], 'attachments.*' => ['file', 'max:10240'],
        ]);
        if (blank($this->locationId) && blank($this->place)) {
            $this->addError('place', __('messages.report.location_or_place_required'));

            return;
        }
        $check = $this->intakeCheck();
        if ($check && ! in_array($check->status, ['ready', 'checklist'], true)) {
            $check = null;
        }
        $reports->submit([
            'branch_id' => $data['branchId'], 'employee_id' => $data['employeeId'] ?: null,
            'employee_ids' => array_values(array_unique(array_map('intval', array_merge(
                array_filter([$data['employeeId'] ?: null]), $data['employeeIds'])))),
            'location_id' => $data['locationId'] ?: null, 'place_freetext' => $data['place'] ?: null,
            'occurred_at' => $data['occurredAt'], 'violation_type' => $data['violationType'],
            'violation_type_id' => $data['violationTypeId'] ?: null,
            'alleged_responsible_employee_id' => $data['responsibleEmployeeId'] ?: null,
            'alleged_responsible_person_freetext' => $data['responsibleName'] ?: null,
            'description' => $data['description'],
            'witnesses' => array_map(fn ($name) => ['name' => trim($name)], array_filter(explode(',', $data['witnessNames'] ?? ''))),
            'attachments' => $this->attachments,
            'intake_check' => $check, 'intake_answers' => $this->intakeAnswers,
        ], auth()->user());
        session()->flash('status', __('Report sent. HR will review it.'));
        $this->redirect('/reports', navigate: true);
    }

    public function render(): View
    {
        $this->mount();
        $branchIds = DB::table('branch_user')->where('company_id', auth()->user()->company_id)->where('user_id', auth()->id())->pluck('branch_id');

        return view('livewire.reports.submit-report', [
            'branches' => Branch::query()->whereIn('id', $branchIds)->get(),
            'locations' => $this->branchId ? Location::query()->where('branch_id', $this->branchId)->where('is_active', true)->get() : collect(),
            'violationTypes' => ViolationType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'employees' => $this->branchId ? Employee::query()->where('branch_id', $this->branchId)->where('is_active', true)
                ->when($this->employeeSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->employeeSearch.'%'))->get() : collect(),
            'responsibleEmployees' => $this->branchId ? Employee::query()->where('branch_id', $this->branchId)->where('is_active', true)
                ->when($this->responsibleSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->responsibleSearch.'%'))->get() : collect(),
            'intakeCheck' => $this->intakeCheck(),
        ])->layout('layouts.workspace');
    }

    protected function intakeFields(): array
    {
        return ['description' => $this->description,
            'violation_type' => $this->violationTypeId !== '' ? (ViolationType::query()->find($this->violationTypeId)?->name() ?? '') : $this->violationType,
            'place' => $this->locationId !== '' ? (Location::query()->find($this->locationId)?->name ?? '') : $this->place,
            'time' => $this->occurredAt];
    }

    protected function authorizeIntakeCheck(): void
    {
        $this->mount();
    }
}
