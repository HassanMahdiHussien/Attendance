<?php

namespace App\Filament\Admin\Pages\Leave;

use App\Filament\Admin\Resources\Leave\LeavePage;
use App\Models\Employee;
use App\Models\Leave\Allocation;
use App\Models\Leave\Credit;
use App\Models\Leave\RequestDecision;
use App\Models\Leave\Setting;
use App\Services\Leave\LeaveActions;
use App\Services\Leave\LeaveSettings;
use Illuminate\Support\Facades\DB;

class CreditLedger extends LeavePage
{
    protected static ?string $slug = 'leave/credits';

    protected string $view = 'filament.admin.pages.leave.credits';

    public string $employeeId = '';

    public string $reason = '';

    public string $expiryDays = '';

    public string $settingReason = '';

    public array $leaveCalendarRestDays = [5];

    public string $calendarReason = '';

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

    public function employees(): array
    {
        return Employee::query()->where('company_id', $this->company()->id)->orderBy('name')->pluck('name', 'id')->all();
    }

    public function employee(): ?Employee
    {
        return ctype_digit($this->employeeId) ? Employee::query()->where('company_id', $this->company()->id)
            ->findOrFail((int) $this->employeeId) : null;
    }

    public function credits(): array
    {
        $employee = $this->employee();
        if ($employee === null) {
            return [];
        }

        return Credit::query()->with('sourceDay.request')->where('company_id', $this->company()->id)
            ->where('employee_id', $employee->id)->whereNull('superseded_by_run_id')
            ->orderBy('id')->get()->all();
    }

    public function deductions(): array
    {
        $employee = $this->employee();
        if ($employee === null) {
            return [];
        }

        return Allocation::query()->with(['targetDay', 'sourceCredit.sourceDay.request'])
            ->where('company_id', $this->company()->id)->where('employee_id', $employee->id)
            ->whereNotNull('source_credit_id')->whereNull('superseded_by_run_id')->orderBy('id')->get()->all();
    }

    public function reversals(): array
    {
        $employee = $this->employee();
        if ($employee === null) {
            return [];
        }

        return RequestDecision::query()->where('company_id', $this->company()->id)
            ->where('employee_id', $employee->id)->whereIn('decision', ['reverse_deduction', 'withdraw'])
            ->latest('id')->get()->all();
    }

    public function creditUsed(int $creditId): int
    {
        return (int) DB::table('leave_allocations')->where('company_id', $this->company()->id)
            ->where('source_credit_id', $creditId)->whereNull('superseded_by_run_id')->sum('minutes');
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
