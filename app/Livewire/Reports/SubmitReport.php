<?php

namespace App\Livewire\Reports;

use App\Enums\UserRole;
use App\Livewire\Concerns\ChecksDescription;
use App\Livewire\Concerns\PicksPeople;
use App\Models\Branch;
use App\Models\Location;
use App\Models\ViolationType;
use App\Services\Cases\CaseViolationTypes;
use App\Services\Employees\EmployeePicker;
use App\Services\Reports\ReportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

class SubmitReport extends Component
{
    use ChecksDescription;
    use PicksPeople;
    use WithFileUploads;

    public string $branchId = '';

    public string $locationId = '';

    public string $place = '';

    public string $occurredAt = '';

    public string $violationType = '';

    public string $violationTypeId = '';

    public string $description = '';

    public array $attachments = [];

    #[Locked]
    public bool $reviewing = false;

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()->primary_role, [UserRole::Reporter, UserRole::DeptManager], true), 403);
    }

    public function submit(): void
    {
        $this->mount();
        $this->validatedReport();
        $this->reviewing = true;
    }

    public function editReview(): void
    {
        $this->reviewing = false;
    }

    public function confirmSend(ReportService $reports): void
    {
        $this->mount();
        abort_unless($this->reviewing, 409);
        $data = $this->validatedReport();
        $check = $this->intakeCheck();
        if ($check && ! in_array($check->status, ['ready', 'checklist'], true)) {
            $check = null;
        }
        $employees = $this->pickedPeople('employees');
        $ids = array_values(array_map(fn ($item) => $item['employee_id'], array_filter($employees, fn ($item) => $item['employee_id'] !== null)));
        $reports->submit([
            'branch_id' => $data['branchId'], 'employee_id' => $ids[0] ?? null,
            'employee_ids' => $ids,
            'free_employee_names' => array_values(array_map(fn ($item) => $item['name'], array_filter($employees, fn ($item) => $item['employee_id'] === null))),
            'location_id' => $data['locationId'] ?: null, 'place_freetext' => $data['place'] ?: null,
            'occurred_at' => $data['occurredAt'], 'violation_type' => $data['violationType'],
            'violation_type_id' => $data['violationTypeId'] ?: null,
            'description' => $data['description'], 'witnesses' => $this->pickedPeople('witnesses'),
            'attachments' => $this->attachments,
            'intake_check' => $check, 'intake_answers' => $this->intakeAnswers,
        ], auth()->user());
        session()->flash('status', __('Report sent. HR will review it.'));
        $this->redirect('/reports', navigate: true);
    }

    private function validatedReport(): array
    {
        $data = $this->validate([
            'branchId' => ['required', 'integer'],
            'locationId' => ['nullable', 'integer'], 'place' => ['nullable', 'string', 'max:255'],
            'occurredAt' => ['required', 'date', 'before_or_equal:now'], 'violationType' => ['nullable', 'string', 'max:255'],
            'violationTypeId' => ['nullable', 'integer'],
            'description' => ['required', 'string'],
            'attachments' => ['array'], 'attachments.*' => ['file', 'max:10240'],
        ]);
        if (blank($this->locationId) && blank($this->place)) {
            throw ValidationException::withMessages(['place' => __('messages.report.location_or_place_required')]);
        }
        app(EmployeePicker::class)->authorizeBranch(auth()->user(), (int) $this->branchId);
        if ($this->locationId !== '') {
            Location::query()->where('company_id', auth()->user()->company_id)
                ->where('branch_id', $this->branchId)->where('is_active', true)->findOrFail($this->locationId);
        }
        app(CaseViolationTypes::class)->selected($this->violationTypeId !== '' ? (int) $this->violationTypeId : null,
            $this->violationType, (int) auth()->user()->company_id);
        $this->pickedPeople('employees');
        $this->pickedPeople('witnesses');

        return $data;
    }

    public function render(EmployeePicker $picker): View
    {
        $this->mount();
        $branchIds = DB::table('branch_user')->where('company_id', auth()->user()->company_id)->where('user_id', auth()->id())->pluck('branch_id');

        return view('livewire.reports.submit-report', [
            'branches' => Branch::query()->whereIn('id', $branchIds)->get(),
            'locations' => $this->branchId ? Location::query()->where('branch_id', $this->branchId)->where('is_active', true)->get() : collect(),
            'violationTypes' => ViolationType::query()->where('is_active', true)->orderBy('sort_order')->get(),
            'peopleResults' => $this->peopleResults($picker),
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

    protected function allowsFreeEmployees(): bool
    {
        return true;
    }
}
