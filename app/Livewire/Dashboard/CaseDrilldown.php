<?php

namespace App\Livewire\Dashboard;

use App\Services\Dashboards\CaseMetrics;
use App\Services\Dashboards\DashboardFilters;
use App\Support\Access\DashboardNav;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class CaseDrilldown extends Component
{
    public bool $queryChecked = false;

    use WithPagination;

    #[Url]
    public string $metric = '';

    #[Url]
    public string $group = '';

    #[Url]
    public string $key = '';

    #[Url]
    public string $violation = '';

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

    public function render(): View
    {
        $this->mount();
        $viewer = auth()->user();
        try {
            $filters = DashboardFilters::fromInput($viewer, array_filter([
                'branch' => $this->branch, 'period' => $this->period, 'from' => $this->from, 'to' => $this->to,
            ], fn ($value) => $value !== ''));
        } catch (ValidationException $error) {
            // Validation thrown from render() cannot redirect back; show it on the fields and keep the default view.
            $this->setErrorBag($error->validator->errors());
            $filters = DashboardFilters::fromInput($viewer, ['period' => '6']);
        }
        $service = app(CaseMetrics::class);
        if ($this->metric === 'hotspot') {
            $query = $service->hotspotQuery($viewer, $filters, $this->group, $this->key);
        } elseif ($this->metric === 'threshold') {
            abort_unless($this->violation !== '' && $this->key !== '', 404);
            $query = $service->thresholdQuery($viewer, $filters, $this->group, $this->key, $this->violation, $viewer->company->hotspot_window_days);
        } else {
            abort_unless($this->group === '' && $this->key === '' && $this->violation === '', 404);
            $query = $service->metricQuery($viewer, $filters, $this->metric);
        }

        return view('livewire.dashboard.case-drilldown', [
            'cases' => $query->with('employee')->orderByDesc('cases.created_at')->paginate(25),
        ])->layout('layouts.workspace');
    }
}
