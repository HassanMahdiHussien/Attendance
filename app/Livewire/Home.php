<?php

namespace App\Livewire;

use App\Enums\UserRole;
use Illuminate\Contracts\View\View;
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

        return view('livewire.home')->layout('layouts.workspace');
    }
}
