<?php

namespace App\Livewire\Cases;

use App\Services\Cases\PoolEligibility;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class PendingApprovals extends Component
{
    public function render(): View
    {
        $documents = app(PoolEligibility::class)->pendingFor(auth()->user());

        return view('livewire.cases.pending-approvals', compact('documents'))->layout('layouts.workspace');
    }
}
