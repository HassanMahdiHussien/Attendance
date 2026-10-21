<?php

namespace App\Livewire;

use App\Enums\UserRole;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Home extends Component
{
    public function mount(): void
    {
        $user = auth()->user();
        abort_unless($user->is_active && ($user->company_id === null || $user->company?->is_active), 403);
        if ($user->primary_role === UserRole::PlatformAdmin) {
            $this->redirect('/platform');
        }
    }

    public function render(): View
    {
        $this->mount();

        return view('livewire.home')->layout('layouts.workspace');
    }
}
