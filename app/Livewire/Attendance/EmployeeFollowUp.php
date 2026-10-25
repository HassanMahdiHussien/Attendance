<?php

namespace App\Livewire\Attendance;

use App\Models\Employee;
use App\Services\Attendance\FollowUp\FollowUpCases;
use App\Services\Employees\EmployeeHistory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

class EmployeeFollowUp extends Component
{
    #[Locked]
    public int $employeeId;

    public string $email = '';

    public string $fallbackEmail = '';

    public string $effectiveDate = '';

    public string $reason = '';

    public function mount(int $employeeId): void
    {
        $this->employeeId = $employeeId;
        $employee = $this->employee();
        $this->email = $employee->email ?? '';
        $this->fallbackEmail = $employee->fallback_notification_email ?? '';
        $this->effectiveDate = now('Asia/Baghdad')->toDateString();
    }

    public function save(EmployeeHistory $history): void
    {
        $employee = $this->employee();
        Gate::authorize('update', $employee);
        $this->validate([
            'email' => 'nullable|email|max:255', 'fallbackEmail' => 'nullable|email|max:255',
            'effectiveDate' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:1000',
        ]);
        app(TenantContext::class)->runForCompany($employee->company, fn () => $history->record($employee, [
            'email' => $this->email === '' ? null : $this->email,
            'fallback_notification_email' => $this->fallbackEmail === '' ? null : $this->fallbackEmail,
        ], $this->effectiveDate, 'manual', auth()->user(), $this->reason));
        $employee->refresh();
        $this->email = $employee->email ?? '';
        $this->fallbackEmail = $employee->fallback_notification_email ?? '';
        $this->reason = '';
    }

    public function render(): View
    {
        $employee = $this->employee();
        $cases = app(TenantContext::class)->runForCompany($employee->company,
            fn () => app(FollowUpCases::class)->forEmployee($employee));

        return view('livewire.attendance.employee-follow-up', compact('employee', 'cases'));
    }

    private function employee(): Employee
    {
        $employee = Employee::withoutGlobalScopes()->with('company')->findOrFail($this->employeeId);
        Gate::authorize('view', $employee);
        abort_unless(auth()->user()?->is_active && $employee->company?->is_active, 403);

        return $employee;
    }
}
