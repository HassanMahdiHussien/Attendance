<?php

namespace App\Livewire\Dashboard;

use App\Models\Branch;
use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Support\Access\DashboardNav;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;

class HrDashboard extends Component
{
    public bool $queryChecked = false;

    #[Url]
    public string $branch = '';

    #[Url]
    public string $period = '6';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        abort_unless(DashboardNav::visible(auth()->user()), 403);
        // Query-string filters are checked once, on the page load: an invalid value in the URL is a 404, while
        // later filter changes made on the page are validated in render() and shown as field errors.
        if (! $this->queryChecked) {
            try {
                DashboardFilters::fromInput(auth()->user(), array_filter([
                    'branch' => $this->branch, 'period' => $this->period, 'from' => $this->from, 'to' => $this->to,
                ], fn ($value) => $value !== ''));
            } catch (ValidationException) {
                abort(404);
            }
            $this->queryChecked = true;
        }
    }

    public function updatedPeriod(): void
    {
        if ($this->period === 'custom' && ($this->from === '' || $this->to === '')) {
            $today = CarbonImmutable::now('Asia/Baghdad');
            $this->from = $today->subMonths(6)->toDateString();
            $this->to = $today->toDateString();
        }
    }

    public function render(): View
    {
        $this->mount();
        $user = auth()->user();
        try {
            $filters = DashboardFilters::fromInput($user, array_filter([
                'branch' => $this->branch, 'period' => $this->period, 'from' => $this->from, 'to' => $this->to,
            ], fn ($value) => $value !== ''));
        } catch (ValidationException $error) {
            // Validation thrown from render() cannot redirect back; show it on the fields and keep the default view.
            $this->setErrorBag($error->validator->errors());
            $filters = DashboardFilters::fromInput($user, ['period' => '6']);
        }
        $service = app(CaseMetrics::class);

        return view('livewire.dashboard.hr-dashboard', [
            'alerts' => $service->alerts($user, $filters),
            'counts' => $service->counts($user, $filters),
            'hotspots' => $service->hotspots($user, $filters),
            'thresholds' => $service->thresholds($user, $filters, $user->company->hotspot_min_cases, $user->company->hotspot_window_days),
            'branches' => Branch::query()->where('company_id', $user->company_id)->orderBy('name')->get(),
        ])->layout('layouts.workspace');
    }
}
