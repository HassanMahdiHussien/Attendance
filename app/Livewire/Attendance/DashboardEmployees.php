<?php

namespace App\Livewire\Attendance;

use App\Enums\UserRole;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Services\Leave\LeaveBalances;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DashboardEmployees extends Component
{
    #[Locked]
    public int $companyId;

    #[Locked]
    public string $tile;

    #[Locked]
    public array $filters = [];

    public function mount(?string $company = null): void
    {
        $tile = request()->query('tile');
        abort_unless(is_string($tile) && ReportTiles::allowed($tile), 404);
        $this->tile = $tile;
        $viewer = auth()->user();
        if ($company !== null) {
            abort_unless($viewer?->primary_role === UserRole::PlatformAdmin && ctype_digit($company), 404);
            $this->companyId = (int) $company;
        } else {
            abort_unless($viewer?->primary_role === UserRole::HrStaff, 403);
            $this->companyId = (int) $viewer->company_id;
        }

        $this->company();
        $this->filters = request()->only(['from', 'to', 'employee_ids', 'branch_ids', 'departments',
            'manager_employee_ids', 'work_patterns', 'statuses']);
    }

    public function render(AttendanceSummary $summary, Overtime $overtime): View
    {
        $company = $this->company();

        return app(TenantContext::class)->runForCompany($company, function () use ($company, $summary, $overtime): View {
            $filters = ReportFilters::fromArray($company, $this->filters);
            $ids = in_array($this->tile, ['overtime_minutes', 'entitled_employees', 'entitlements_available_now', 'entitlements_overdue_now'], true)
                ? $overtime->drillDownEmployeeIds($filters, $this->tile)
                : $summary->drillDown($filters, $this->tile);
            $employees = $ids === [] ? collect() : Employee::query()->where('company_id', $company->id)
                ->whereIn('id', $ids)->with('branch')->orderBy('name')->get();
            $balances = app(LeaveBalances::class)->currentFor($company->id, $employees->pluck('id')->all());
            $overtimeRows = [];
            if (in_array($this->tile, ['overtime_minutes', 'entitled_employees', 'entitlements_available_now', 'entitlements_overdue_now'], true)) {
                foreach ($overtime->perEmployee($filters) as $row) {
                    $overtimeRows[$row['employee_id']] = $row;
                }
            }

            return view('livewire.attendance.dashboard-employees', [
                'company' => $company, 'employees' => $employees, 'balances' => $balances,
                'overtimeRows' => $overtimeRows,
                'from' => $filters->from, 'to' => $filters->to,
            ])->layout('layouts.workspace');
        });
    }

    private function company(): Company
    {
        $viewer = auth()->user();
        abort_unless($viewer?->is_active, 403);
        abort_unless($viewer->primary_role === UserRole::PlatformAdmin
            || ($viewer->primary_role === UserRole::HrStaff && (int) $viewer->company_id === $this->companyId && $viewer->company?->is_active), 404);

        return Company::query()->findOrFail($this->companyId);
    }
}
