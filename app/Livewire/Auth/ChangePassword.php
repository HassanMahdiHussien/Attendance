<?php

namespace App\Livewire\Auth;

use App\Services\UserAccountService;
use App\Support\Access\Landing;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ChangePassword extends Component
{
    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public function save(UserAccountService $accounts): void
    {
        $this->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'confirmed'],
        ]);
        $user = auth()->user();
        $accounts->changeOwnPassword($user, $this->current_password, $this->new_password);
        session()->flash('status', __('messages.password.changed'));
        $target = (string) session()->pull('url.intended', Landing::for($user));
        if (parse_url($target, PHP_URL_PATH) === route('account.password', absolute: false)) {
            $target = Landing::for($user);
        }
        $this->redirect($target, navigate: false);
    }

    public function render(): View
    {
        return view('livewire.auth.change-password')->layout('layouts.workspace');
    }
}
