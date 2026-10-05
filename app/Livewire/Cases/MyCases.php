<?php

namespace App\Livewire\Cases;

use App\Enums\UserRole;
use App\Services\Cases\CaseBoard;
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

        return view('livewire.cases.my-cases', ['cases' => app(CaseBoard::class)->mine(auth()->user())])->layout('layouts.workspace');
    }
}
