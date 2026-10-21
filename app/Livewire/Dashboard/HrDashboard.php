<?php

namespace App\Livewire\Dashboard;

use App\Support\Access\DashboardNav;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class HrDashboard extends Component
{
    public function mount(): void
    {
        abort_unless(DashboardNav::visible(auth()->user()), 403);
    }

    public function render(): View
    {
        $this->mount();

        return view('livewire.dashboard.hr-dashboard')->layout('layouts.workspace');
    }
}
