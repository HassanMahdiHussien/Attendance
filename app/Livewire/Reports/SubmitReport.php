<?php

namespace App\Livewire\Reports;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Location;
use App\Services\Reports\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

class SubmitReport extends Component
{
    use WithFileUploads;

    public string $branchId = '';

    public string $employeeId = '';

    public string $employeeSearch = '';

    public string $locationId = '';

    public string $place = '';

    public string $occurredAt = '';

    public string $violationType = '';

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
            'locationId' => ['nullable', 'integer'], 'place' => ['nullable', 'string', 'max:255'],
            'occurredAt' => ['required', 'date'], 'violationType' => ['required', 'string', 'max:255'],
            'responsibleEmployeeId' => ['nullable', 'integer'], 'responsibleName' => ['nullable', 'string', 'max:255'],
            'witnessNames' => ['nullable', 'string'], 'description' => ['required', 'string'],
            'attachments' => ['array'], 'attachments.*' => ['file', 'max:10240'],
        ]);
        if (blank($this->locationId) && blank($this->place)) {
            $this->addError('place', __('messages.report.location_or_place_required'));

            return;
        }
        $reports->submit([
            'branch_id' => $data['branchId'], 'employee_id' => $data['employeeId'] ?: null,
            'location_id' => $data['locationId'] ?: null, 'place_freetext' => $data['place'] ?: null,
            'occurred_at' => $data['occurredAt'], 'violation_type' => $data['violationType'],
            'alleged_responsible_employee_id' => $data['responsibleEmployeeId'] ?: null,
            'alleged_responsible_person_freetext' => $data['responsibleName'] ?: null,
            'description' => $data['description'],
            'witnesses' => array_map(fn ($name) => ['name' => trim($name)], array_filter(explode(',', $data['witnessNames'] ?? ''))),
            'attachments' => $this->attachments,
        ], auth()->user());
        $this->redirect('/reports', navigate: true);
    }

    public function render(): View
    {
        $this->mount();
        $branchIds = DB::table('branch_user')->where('company_id', auth()->user()->company_id)->where('user_id', auth()->id())->pluck('branch_id');

        return view('livewire.reports.submit-report', [
            'branches' => Branch::query()->whereIn('id', $branchIds)->get(),
            'locations' => $this->branchId ? Location::query()->where('branch_id', $this->branchId)->where('is_active', true)->get() : collect(),
            'employees' => $this->branchId ? Employee::query()->where('branch_id', $this->branchId)->where('is_active', true)
                ->when($this->employeeSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->employeeSearch.'%'))->get() : collect(),
            'responsibleEmployees' => $this->branchId ? Employee::query()->where('branch_id', $this->branchId)->where('is_active', true)
                ->when($this->responsibleSearch !== '', fn ($q) => $q->where('name', 'like', '%'.$this->responsibleSearch.'%'))->get() : collect(),
        ])->layout('layouts.workspace');
    }
}
