<?php

namespace App\Livewire\Reports;

use App\Enums\UserRole;
use App\Support\Access\EmailDraftVisibility;
use App\Support\Access\ViolationReportVisibility;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class MyReports extends Component
{
    public function mount(): void
    {
        abort_unless(in_array(auth()->user()->primary_role, [UserRole::Reporter, UserRole::DeptManager], true), 403);
    }

    public function render(): View
    {
        $this->mount();

        return view('livewire.reports.my-reports', [
            'reports' => ViolationReportVisibility::for(auth()->user())->latest()->get(),
            'emails' => EmailDraftVisibility::for(auth()->user())->with('disciplinaryCase')->latest('received_at')->get(),
        ])->layout('layouts.workspace');
    }
}
