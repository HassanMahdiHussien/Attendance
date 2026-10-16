<?php

namespace App\Livewire\Help;

use App\Support\Help\HelpLibrary;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

class HelpIndex extends Component
{
    #[Url]
    public string $q = '';

    #[Url]
    public bool $all = false;

    public function updatedQ(): void
    {
        $this->validate(['q' => 'string|max:100']);
    }

    public function render(HelpLibrary $library): View
    {
        $this->validate(['q' => 'string|max:100']);
        $locale = app()->getLocale();
        $role = $this->all ? null : auth()->user()->primary_role;
        $articles = $library->search($locale, $this->q, $role);

        return view('livewire.help.index', ['sections' => $library->sections(), 'articles' => $articles])->layout('layouts.workspace');
    }
}
