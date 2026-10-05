<?php

namespace App\Livewire\Cases;

use App\Enums\CaseStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\DisciplinaryCase;
use App\Services\Cases\CaseBoard;
use App\Services\Cases\Deadlines;
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
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'branch', 'stage'], true)) {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        Gate::authorize('viewAny', DisciplinaryCase::class);
        $board = app(CaseBoard::class);
        $user = auth()->user();

        return view('livewire.cases.board', [
            'cases' => $board->page($user, $this->search, $this->branch, $this->stage),
            'urgentCount' => $board->urgentCount($user, $this->search, $this->branch, $this->stage),
            'branches' => Branch::query()->where('company_id', auth()->user()->company_id)->orderBy('name')->get(),
            'statuses' => CaseStatus::cases(), 'deadlines' => app(Deadlines::class),
        ])->layout('layouts.workspace');
    }
}
