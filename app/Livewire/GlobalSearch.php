<?php

namespace App\Livewire;

use App\Services\Search\GlobalSearch as SearchService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class GlobalSearch extends Component
{
    public string $query = '';

    public function mount(): void
    {
        abort_unless(app(SearchService::class)->allowed(auth()->user()), 403);
    }

    public function render(): View
    {
        $this->mount();

        return view('livewire.global-search', [
            'groups' => app(SearchService::class)->for(auth()->user(), $this->query),
        ]);
    }
}
