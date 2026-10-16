<?php

namespace App\Livewire\Help;

use App\Support\Help\HelpArticle;
use App\Support\Help\HelpLibrary;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class HelpArticlePage extends Component
{
    public string $section;

    public string $slug;

    public function mount(string $section, string $slug): void
    {
        $this->section = $section;
        $this->slug = $slug;
    }

    public function render(HelpLibrary $library): View
    {
        $article = $library->find(app()->getLocale(), $this->section, $this->slug);
        abort_unless($article, 404);
        $siblings = array_values(array_filter($library->all(app()->getLocale()), fn (HelpArticle $item): bool => $item->section === $this->section));
        $position = array_search($article->slug, array_map(fn (HelpArticle $item): string => $item->slug, $siblings), true);
        $section = collect($library->sections())->firstWhere('slug', $this->section);

        return view('livewire.help.article', [
            'article' => $article, 'siblings' => $siblings, 'sectionName' => $section['name'],
            'previous' => $siblings[$position - 1] ?? null, 'next' => $siblings[$position + 1] ?? null,
        ])->layout('layouts.workspace');
    }
}
