<?php

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\Company;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class Home extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->primary_role === UserRole::HrPartnerStaff, 403);
    }

    public function render(): View
    {
        $this->mount();

        $user = auth()->user();
        $links = DB::table('company_hr_partner')->where('hr_partner_id', $user->hr_partner_id)->get();
        $companies = Company::query()->whereIn('id', $links->pluck('company_id'))->get()->keyBy('id');
        $filters = DashboardFilters::fromInput($user, ['period' => '6']);
        $metrics = app(CaseMetrics::class);
        $overview = [];
        foreach ($links as $link) {
            $company = $companies->get($link->company_id);
            if (! $company) {
                continue;
            }
            $overview[] = ['company' => $company, 'full_access' => (bool) $link->full_case_access,
                'counts' => $metrics->counts($user, $filters, true, $company->id),
                'hotspots' => $metrics->hotspots($user, $filters, true, $company->id)];
        }

        return view('livewire.home', ['overview' => $overview])->layout('layouts.workspace');
    }
}
