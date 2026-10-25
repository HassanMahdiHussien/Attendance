<?php

namespace App\Livewire\Dashboard;

use App\Enums\UserRole;
use App\Filament\Admin\Pages\Attendance\FollowUpCasesPage;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Attendance\FollowUp\FollowUpCases;
use App\Services\Attendance\Overtime;
use App\Services\Attendance\Reporting\AttendanceExports;
use App\Services\Attendance\Reporting\AttendanceSummary;
use App\Services\Attendance\Reporting\EmployeeFilterSearch;
use App\Services\Attendance\Reporting\ExportKinds;
use App\Services\Attendance\Reporting\LastUpdate;
use App\Services\Attendance\Reporting\ReportFilters;
use App\Services\Attendance\Reporting\ReportTiles;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class HrDashboard extends Component
{
    #[Locked]
    public ?int $companyId = null;

    public string $from = '';

    public string $to = '';

    public string $employeeId = '';

    public string $employeeSearch = '';

    public string $branchId = '';

    public string $department = '';

    public string $managerId = '';

    public string $managerSearch = '';

    public string $workPattern = '';

    public string $status = '';

    public string $exportKind = 'lateness_reconciliation';

    public string $exportLocale = 'en';

    public bool $exportQueued = false;

    public function mount(?string $company = null): void
    {
        $viewer = auth()->user();
        abort_unless($viewer?->is_active && ($viewer->company_id === null || $viewer->company?->is_active), 403);
        if ($company !== null) {
            abort_unless($viewer->primary_role === UserRole::PlatformAdmin && ctype_digit($company), 404);
            $this->companyId = (int) $company;
        } else {
            abort_unless($viewer->primary_role === UserRole::HrStaff, 403);
            $this->companyId = (int) $viewer->company_id;
        }

        $this->company();
        $this->period('current');
    }

    public function period(string $choice): void
    {
        $this->company();
        $today = CarbonImmutable::now('Asia/Baghdad');
        abort_unless(in_array($choice, ['current', 'previous', 'last_30'], true), 404);
        [$this->from, $this->to] = match ($choice) {
            'current' => [$today->startOfMonth()->toDateString(), $today->toDateString()],
            'previous' => [$today->subMonthNoOverflow()->startOfMonth()->toDateString(), $today->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'last_30' => [$today->subDays(29)->toDateString(), $today->toDateString()],
        };
    }

    public function apply(): void
    {
        $this->company();
    }

    public function updatedEmployeeSearch(): void
    {
        $this->employeeId = '';
    }

    public function updatedManagerSearch(): void
    {
        $this->managerId = '';
    }

    public function chooseEmployee(int $id, EmployeeFilterSearch $search): void
    {
        $company = $this->company();
        $selected = app(TenantContext::class)->runForCompany($company,
            fn (): array => $search->selected(auth()->user(), $company, $id));
        $this->employeeId = (string) $selected['id'];
        $this->employeeSearch = $selected['name'].' · '.$selected['code'];
    }

    public function chooseManager(int $id, EmployeeFilterSearch $search): void
    {
        $company = $this->company();
        $selected = app(TenantContext::class)->runForCompany($company,
            fn (): array => $search->selected(auth()->user(), $company, $id));
        $this->managerId = (string) $selected['id'];
        $this->managerSearch = $selected['name'].' · '.$selected['code'];
    }

    public function clearEmployee(): void
    {
        $this->company();
        $this->employeeId = '';
        $this->employeeSearch = '';
    }

    public function clearManager(): void
    {
        $this->company();
        $this->managerId = '';
        $this->managerSearch = '';
    }

    public function requestExport(AttendanceExports $exports): void
    {
        $company = $this->company();
        app(TenantContext::class)->runForCompany($company, function () use ($company, $exports): void {
            abort_unless(in_array($this->exportKind, ExportKinds::ALL, true)
                && ! in_array($this->exportKind, ExportKinds::EMPLOYEE_ONLY, true), 403);
            $exports->request($company, auth()->user(), $this->exportKind, $this->filters(), $this->exportLocale);
        });
        $this->exportQueued = true;
    }

    public function tileUrl(string $tile): string
    {
        $company = $this->company();
        abort_unless(ReportTiles::allowed($tile), 404);
        if (in_array($tile, FollowUpCases::TILES, true)) {
            return FollowUpCasesPage::getUrl(panel: 'admin', tenant: $company)
                .'?'.http_build_query(['state' => $tile === 'open_cases' ? 'open_cases' : $tile] + $this->filters());
        }
        $route = auth()->user()->primary_role === UserRole::PlatformAdmin ? 'dashboard.company.employees' : 'dashboard.employees';
        $parameters = $route === 'dashboard.company.employees' ? ['company' => $company->id] : [];

        return route($route, $parameters).'?'.http_build_query(['tile' => $tile] + $this->filters());
    }

    public function render(AttendanceSummary $summary, LastUpdate $lastUpdate, EmployeeFilterSearch $search, Overtime $overtime): View
    {
        $company = $this->company();

        return app(TenantContext::class)->runForCompany($company, function () use ($company, $summary, $lastUpdate, $search, $overtime): View {
            $filters = ReportFilters::fromArray($company, $this->filters());
            $totals = $summary->totals($filters);
            $overtimeTotals = $overtime->totals($filters);
            $employees = $this->employeeId === '' ? $search->find(auth()->user(), $company, $this->employeeSearch) : [];
            $managers = $this->managerId === '' ? $search->find(auth()->user(), $company, $this->managerSearch) : [];

            return view('livewire.dashboard.hr-dashboard', [
                'company' => $company, 'tiles' => ReportTiles::fromTotals($totals) + ReportTiles::overtime($overtimeTotals)
                    + app(FollowUpCases::class)->tileTotals($filters),
                'totals' => $totals,
                'freshness' => $lastUpdate->for($filters), 'employees' => $employees, 'managers' => $managers,
                'branches' => Branch::query()->where('company_id', $company->id)->orderBy('name')->get(),
                'departments' => Employee::query()->where('company_id', $company->id)
                    ->whereNotNull('department')->where('department', '<>', '')
                    ->distinct()->orderBy('department')->pluck('department'),
            ])->layout('layouts.workspace');
        });
    }

    private function filters(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to,
            'employee_ids' => $this->employeeId === '' ? [] : [$this->employeeId],
            'branch_ids' => $this->branchId === '' ? [] : [$this->branchId],
            'departments' => $this->department === '' ? [] : [$this->department],
            'manager_employee_ids' => $this->managerId === '' ? [] : [$this->managerId],
            'work_patterns' => $this->workPattern === '' ? [] : [$this->workPattern],
            'statuses' => $this->status === '' ? [] : [$this->status],
        ];
    }

    private function company(): Company
    {
        $viewer = auth()->user();
        abort_unless($viewer?->is_active && $this->companyId !== null, 403);
        abort_unless($viewer->primary_role === UserRole::PlatformAdmin
            || ($viewer->primary_role === UserRole::HrStaff && (int) $viewer->company_id === $this->companyId && $viewer->company?->is_active), 404);

        return Company::query()->findOrFail($this->companyId);
    }
}
