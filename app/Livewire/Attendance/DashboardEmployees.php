<?php

namespace App\Livewire\Attendance;

use App\Enums\UserRole;
use App\Models\Attendance\Day;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\FollowUp\FollowUpCases;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\DayPresentation;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Services\Leave\LeaveBalances;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Locked;
use Livewire\Component;

class DashboardEmployees extends Component
{
    public string $search = '';

    public int $page = 1;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

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
            'manager_employee_ids', 'work_patterns', 'shifts', 'statuses']);
        $this->search = (string) request()->query('search', '');
        $page = request()->query('page', 1);
        $this->page = ctype_digit((string) $page) ? max(1, (int) $page) : 1;
    }

    public function render(AttendanceSummary $summary, Overtime $overtime): View
    {
        $company = $this->company();

        return app(TenantContext::class)->runForCompany($company, function () use ($company, $summary, $overtime): View {
            $filters = ReportFilters::fromArray($company, $this->filters);
            $rows = [];
            if (in_array($this->tile, FollowUpCases::TILES, true)) {
                $ids = app(FollowUpCases::class)->employeeIds($filters, $this->tile);
            } elseif (in_array($this->tile, ['overtime_minutes', 'entitled_employees', 'entitlements_available_now', 'entitlements_overdue_now'], true)) {
                $ids = $overtime->drillDownEmployeeIds($filters, $this->tile);
            } else {
                $rows = $summary->drillDownRows($filters, $this->tile);
                $ids = array_keys($rows);
            }
            $names = $ids === [] ? collect() : Employee::query()->where('company_id', $company->id)
                ->whereIn('id', $ids)->when(trim($this->search) !== '', function ($query): void {
                    $term = trim($this->search);
                    $query->where(fn ($matches) => $matches->where('name', 'like', '%'.$term.'%')
                        ->orWhere('name_en', 'like', '%'.$term.'%')->orWhere('employee_code', 'like', '%'.$term.'%'));
                })->pluck('name', 'id');
            // Biggest figure first so HR starts with the employees who matter most.
            $sortedIds = $names->keys()->sort(function ($a, $b) use ($names, $rows): int {
                $valueOrder = ((float) ($rows[$b]['value'] ?? 0)) <=> ((float) ($rows[$a]['value'] ?? 0));

                return $valueOrder !== 0 ? $valueOrder : strcmp($names[$a], $names[$b]);
            })->values();
            $total = $sortedIds->count();
            $this->page = max(1, min($this->page, max(1, (int) ceil($total / 50))));
            $pageIds = $sortedIds->forPage($this->page, 50)->values();
            $byId = $pageIds->isEmpty() ? collect() : Employee::query()->where('company_id', $company->id)
                ->whereIn('id', $pageIds)->with('branch')->get()->keyBy('id');
            $employees = new LengthAwarePaginator($pageIds->map(fn ($id) => $byId->get($id))->filter()->values(), $total, 50, $this->page,
                ['path' => request()->url(), 'query' => request()->query()]);
            $visible = $employees->getCollection();
            $dayIds = [];
            $dayCounts = [];
            foreach ($visible as $employee) {
                $idsForEmployee = $rows[$employee->id]['day_ids'] ?? [];
                $dayCounts[$employee->id] = count($idsForEmployee);
                array_push($dayIds, ...array_slice($idsForEmployee, 0, 8));
            }
            $days = $dayIds === [] ? collect() : Day::query()->where('company_id', $company->id)
                ->whereIn('id', $dayIds)->get(['id', 'employee_id', 'work_date', 'first_in', 'last_out', 'shift_evidence'])->keyBy('id');
            $presentations = [];
            $display = app(DayPresentation::class);
            foreach ($summary->presentationRowsForDays($filters, $dayIds) as $id => $row) {
                $presentations[$id] = $display->from($row);
            }
            $balances = app(LeaveBalances::class)->currentFor($company->id, $visible->pluck('id')->all());
            $overtimeRows = [];
            if (in_array($this->tile, ['overtime_minutes', 'entitled_employees', 'entitlements_available_now', 'entitlements_overdue_now'], true)) {
                foreach ($overtime->perEmployee($filters) as $row) {
                    $overtimeRows[$row['employee_id']] = $row;
                }
            }

            return view('livewire.attendance.dashboard-employees', [
                'company' => $company, 'employees' => $employees, 'balances' => $balances,
                'overtimeRows' => $overtimeRows, 'rows' => $rows, 'days' => $days,
                'dayCounts' => $dayCounts, 'presentations' => $presentations,
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
