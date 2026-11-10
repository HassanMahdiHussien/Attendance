<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\RequestDecision;
use App\Models\Leave\Setting;
use App\Services\Attendance\Reporting\EmployeeFilterSearch;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\LeaveSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\WithPagination;

class CreditLedger extends LeavePage
{
    use WithPagination;

    protected static ?string $slug = 'leave/credits';

    protected string $view = 'filament.admin.pages.leave.credits';

    public string $employeeId = '';

    public string $employeeSearch = '';

    public string $reason = '';

    public string $expiryDays = '';

    public string $settingReason = '';

    public array $leaveCalendarRestDays = [5];

    public string $calendarReason = '';

    private array $usedByCredit = [];

    public static function getNavigationLabel(): string
    {
        return __('messages.leave_ui.credits_title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        parent::mount();
        $setting = Setting::query()->where('company_id', $this->company()->id)->first();
        $this->expiryDays = (string) ($setting?->credit_expiry_days ?? '');
        $this->leaveCalendarRestDays = $setting?->leave_calendar_rest_days ?? [5];
    }

    public function searchEmployees(): array
    {
        return $this->employeeId === ''
            ? app(EmployeeFilterSearch::class)->find(auth()->user(), $this->company(), $this->employeeSearch) : [];
    }

    public function updatedEmployeeSearch(): void
    {
        $this->employeeId = '';
        $this->resetLedgerPages();
    }

    public function chooseEmployee(int $id, EmployeeFilterSearch $search): void
    {
        $selected = $search->selected(auth()->user(), $this->company(), $id);
        $this->employeeId = (string) $selected['id'];
        $this->employeeSearch = $selected['name'].' · '.$selected['code'];
        $this->resetLedgerPages();
    }

    public function clearEmployee(): void
    {
        $this->company();
        $this->employeeId = '';
        $this->employeeSearch = '';
        $this->resetLedgerPages();
    }

    private function resetLedgerPages(): void
    {
        foreach (['creditPage', 'deductionPage', 'reversalPage'] as $page) {
            $this->resetPage($page);
        }
    }

    public function employee(): ?Employee
    {
        return ctype_digit($this->employeeId) ? Employee::query()->where('company_id', $this->company()->id)
            ->findOrFail((int) $this->employeeId) : null;
    }

    public function credits(): LengthAwarePaginator
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 404);

        $page = Credit::query()->with('sourceDay.request')->where('company_id', $this->company()->id)
            ->where('employee_id', $employee->id)->whereNull('superseded_by_run_id')
            ->orderBy('id')->paginate(25, pageName: 'creditPage');
        $this->usedByCredit = DB::table('leave_allocations')->where('company_id', $this->company()->id)
            ->whereIn('source_credit_id', $page->getCollection()->pluck('id'))->whereNull('superseded_by_run_id')
            ->selectRaw('source_credit_id, SUM(minutes) AS used')->groupBy('source_credit_id')->pluck('used', 'source_credit_id')->all();

        return $page;
    }

    public function deductions(): LengthAwarePaginator
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 404);

        return Allocation::query()->with(['targetDay', 'sourceCredit.sourceDay.request'])
            ->where('company_id', $this->company()->id)->where('employee_id', $employee->id)
            ->whereNotNull('source_credit_id')->whereNull('superseded_by_run_id')->orderBy('id')->paginate(25, pageName: 'deductionPage');
    }

    public function reversals(): LengthAwarePaginator
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 404);

        return RequestDecision::query()->where('company_id', $this->company()->id)
            ->where('employee_id', $employee->id)->whereIn('decision', ['reverse_deduction', 'withdraw'])
            ->latest('id')->paginate(25, pageName: 'reversalPage');
    }

    public function creditUsed(int $creditId): int
    {
        return (int) ($this->usedByCredit[$creditId] ?? 0);
    }

    public function isWithdrawn(RequestDecision $decision): bool
    {
        $employee = $this->employee();
        abort_unless($employee !== null && (int) $decision->company_id === (int) $this->company()->id
            && (int) $decision->employee_id === (int) $employee->id, 404);

        return RequestDecision::query()->where('company_id', $this->company()->id)
            ->where('employee_id', $employee->id)->where('decision', 'withdraw')
            ->where('values->decision_id', $decision->id)->exists();
    }

    public function reverseDeduction(int $allocationId, LeaveActions $actions): void
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 404);
        $this->validate(['reason' => 'required|string|max:2000']);
        $allocation = Allocation::query()->with('targetDay')->where('company_id', $this->company()->id)
            ->where('employee_id', $employee->id)->whereNotNull('source_credit_id')
            ->whereNull('superseded_by_run_id')->findOrFail($allocationId);
        $actions->reverseDeduction($employee, $allocation->targetDay->work_date->toDateString(), $allocation->target_kind, $this->reason, auth()->user());
        $this->reason = '';
    }

    public function withdraw(int $decisionId, LeaveActions $actions): void
    {
        $employee = $this->employee();
        abort_unless($employee !== null, 404);
        $this->validate(['reason' => 'required|string|max:2000']);
        $decision = RequestDecision::query()->where('company_id', $this->company()->id)
            ->where('employee_id', $employee->id)->where('decision', 'reverse_deduction')->findOrFail($decisionId);
        $actions->withdraw($decision, $this->reason, auth()->user());
        $this->reason = '';
    }

    public function saveExpiry(LeaveSettings $settings): void
    {
        $this->validate(['expiryDays' => 'nullable|integer|min:0|max:65535', 'settingReason' => 'required|string|max:2000']);
        $settings->setCreditExpiry($this->company(), $this->expiryDays === '' ? null : (int) $this->expiryDays,
            $this->settingReason, auth()->user());
        $this->settingReason = '';
    }

    public function saveLeaveCalendar(LeaveSettings $settings): void
    {
        $this->validate(['leaveCalendarRestDays' => 'array', 'leaveCalendarRestDays.*' => 'integer|between:1,7',
            'calendarReason' => 'required|string|max:2000']);
        $settings->setLeaveCalendarRestDays($this->company(), array_map('intval', $this->leaveCalendarRestDays),
            $this->calendarReason, auth()->user());
        $this->calendarReason = '';
    }
}
