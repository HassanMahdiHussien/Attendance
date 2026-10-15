<?php

namespace App\Livewire\Cases;

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\DisciplinaryCase;
use App\Services\Cases\CaseBoard;
use App\Services\Cases\Deadlines;
use App\Services\UserPreferences;
use App\Support\Access\CaseVisibility;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class Board extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $branch = '';

    #[Url]
    public string $stage = '';

    public function mount(): void
    {
        abort_unless(in_array(auth()->user()->primary_role, [UserRole::CompanyAdmin, UserRole::HrStaff], true), 403);
        Gate::authorize('viewAny', DisciplinaryCase::class);
        $preferences = app(UserPreferences::class);
        $saved = $preferences->boardFilters(auth()->user());
        $params = request()->query();
        $valid = $preferences->valid(auth()->user(), [
            'branch' => array_key_exists('branch', $params) ? $params['branch'] : ($saved['branch'] ?? ''),
            'stage' => array_key_exists('stage', $params) ? $params['stage'] : ($saved['stage'] ?? ''),
            'search' => $params['search'] ?? '',
        ]);
        $this->branch = $valid['branch'];
        $this->stage = $valid['stage'];
        $this->search = $valid['search'];
        if (array_key_exists('branch', $params) || array_key_exists('stage', $params)) {
            $preferences->saveBoardFilters(auth()->user(), $valid);
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'branch', 'stage'], true)) {
            $valid = app(UserPreferences::class)->valid(auth()->user(), [
                'search' => $this->search, 'branch' => $this->branch, 'stage' => $this->stage,
            ]);
            $this->search = $valid['search'];
            $this->branch = $valid['branch'];
            $this->stage = $valid['stage'];
            if (in_array($property, ['branch', 'stage'], true)) {
                app(UserPreferences::class)->saveBoardFilters(auth()->user(), $valid);
            }
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->search = $this->branch = $this->stage = '';
        app(UserPreferences::class)->saveBoardFilters(auth()->user(), []);
        $this->resetPage();
    }

    public function removeSearchFilter(): void
    {
        $this->removeFilter('search');
    }

    public function removeBranchFilter(): void
    {
        $this->removeFilter('branch');
    }

    public function removeStageFilter(): void
    {
        $this->removeFilter('stage');
    }

    private function removeFilter(string $filter): void
    {
        if (in_array($filter, ['search', 'branch', 'stage'], true)) {
            $this->{$filter} = '';
            app(UserPreferences::class)->saveBoardFilters(auth()->user(), ['branch' => $this->branch, 'stage' => $this->stage]);
            $this->resetPage();
        }
    }

    public function render(): View
    {
        Gate::authorize('viewAny', DisciplinaryCase::class);
        $board = app(CaseBoard::class);
        $user = auth()->user();
        $cases = $board->page($user, $this->search, $this->branch, $this->stage);
        $incidentIds = $cases->getCollection()->pluck('incident_id')->filter()->unique();
        $visibleIncidentCounts = $incidentIds->isEmpty() ? collect() : CaseVisibility::for($user)
            ->whereIn('incident_id', $incidentIds)->selectRaw('incident_id, COUNT(*) as visible_count')
            ->groupBy('incident_id')->pluck('visible_count', 'incident_id');

        return view('livewire.cases.board', [
            'cases' => $cases,
            'visibleIncidentCounts' => $visibleIncidentCounts,
            'urgentCount' => $board->urgentCount($user, $this->search, $this->branch, $this->stage),
            'branches' => Branch::query()->where('company_id', auth()->user()->company_id)->orderBy('name')->get(),
            'stages' => range(1, 5), 'deadlines' => app(Deadlines::class),
        ])->layout('layouts.workspace');
    }
}
