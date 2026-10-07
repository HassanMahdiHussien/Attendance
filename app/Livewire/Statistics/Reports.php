<?php

namespace App\Livewire\Statistics;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ReportExport;
use App\Policies\EmployeeProfilePolicy;
use App\Services\Statistics\ReportBuilder;
use App\Services\Statistics\ReportExporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Reports extends Component
{
    public string $type = 'periodic';

    public string $period = 'month';

    public string $year = '';

    public string $month = '';

    public string $quarter = '';

    public string $from = '';

    public string $to = '';

    public string $companyId = '';

    public string $branch = '';

    public string $employeeId = '';

    public bool $showPreview = false;

    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user->is_active && in_array($user->primary_role, [UserRole::CompanyAdmin,
            UserRole::HrStaff, UserRole::PlatformAdmin, UserRole::HrPartnerStaff], true), 403);
        $today = now('Asia/Baghdad');
        $this->year = (string) $today->year;
        $this->month = (string) $today->month;
        $this->quarter = (string) ceil($today->month / 3);
        if ($user->company_id) {
            $this->companyId = (string) $user->company_id;
        }
    }

    public function updated(): void
    {
        $this->showPreview = false;
    }

    public function updatedCompanyId(): void
    {
        $this->branch = '';
        $this->employeeId = '';
        if (auth()->user()->primary_role === UserRole::HrPartnerStaff &&
            ! app(EmployeeProfilePolicy::class)->companyAllowed(auth()->user(), (int) $this->companyId)) {
            $this->type = 'periodic';
        }
    }

    public function preview(ReportBuilder $builder): void
    {
        $builder->validate(auth()->user(), $this->type, $this->parameters());
        $this->showPreview = true;
    }

    public function export(string $format, ReportExporter $exporter): void
    {
        $exporter->request(auth()->user(), $this->type, $this->parameters(), $format);
        $this->showPreview = true;
    }

    public function render(ReportBuilder $builder, EmployeeProfilePolicy $policy): View
    {
        $viewer = auth()->user();
        abort_unless($viewer->is_active && in_array($viewer->primary_role, [UserRole::CompanyAdmin,
            UserRole::HrStaff, UserRole::PlatformAdmin, UserRole::HrPartnerStaff], true), 403);
        $companies = match ($viewer->primary_role) {
            UserRole::PlatformAdmin => Company::query()->orderBy('name')->get(),
            UserRole::HrPartnerStaff => Company::query()->whereIn('id', DB::table('company_hr_partner')
                ->select('company_id')->where('hr_partner_id', $viewer->hr_partner_id))->orderBy('name')->get(),
            default => collect(),
        };
        $companyId = $viewer->company_id ?? ($this->companyId !== '' ? (int) $this->companyId : null);
        $personReportsAvailable = $companyId !== null && $policy->companyAllowed($viewer, $companyId);
        $branches = $companyId ? Branch::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('name')->get() : collect();
        $employees = $personReportsAvailable
            ? $policy->scope($viewer)->where('company_id', $companyId)->orderBy('name')->get(['id', 'name', 'employee_code']) : collect();
        $exports = app(TenantContext::class)->runAcrossAllCompanies(fn () => ReportExport::query()
            ->where('requested_by', $viewer->id)->orderByDesc('id')->limit(20)->get());
        $preview = $this->showPreview ? $builder->build($viewer, $this->type, $this->parameters()) : null;

        return view('livewire.statistics.reports', compact('companies', 'branches', 'employees', 'exports', 'preview', 'personReportsAvailable'))
            ->layout('layouts.workspace');
    }

    private function parameters(): array
    {
        return array_filter(['company_id' => $this->companyId, 'branch' => $this->branch,
            'period' => $this->period, 'year' => $this->year, 'month' => $this->month,
            'quarter' => $this->quarter, 'from' => $this->from, 'to' => $this->to,
            'employee_id' => $this->employeeId], fn ($value) => $value !== '');
    }
}
