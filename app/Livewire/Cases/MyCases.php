<?php

namespace App\Livewire\Cases;

use App\Enums\UserRole;
use App\Services\Cases\CaseBoard;
use App\Services\Dashboards\CaseMetrics;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class MyCases extends Component
{
    public function mount(): void
    {
        abort_unless(in_array(auth()->user()->primary_role, [UserRole::CommitteeMember, UserRole::ExternalLegalReviewer], true), 403);
    }

    public function render(): View
    {
        $this->mount();

        $user = auth()->user();

        return view('livewire.cases.my-cases', [
            'cases' => app(CaseBoard::class)->mine($user),
            'summary' => $user->primary_role === UserRole::ExternalLegalReviewer
                ? app(CaseMetrics::class)->reviewerSummary($user) : null,
        ])->layout('layouts.workspace');
    }
}
