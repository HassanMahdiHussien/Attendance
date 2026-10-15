<div class="space-y-6">
    <x-ui.page-header :title="__('Help Center')" />
    <x-ui.card class="space-y-4">
        <p class="text-sm text-gray-600">{{ __('Find a guide for your work.') }}</p>
        <label for="help-search" class="block text-sm font-semibold">{{ __('Search guides') }}</label>
        <input id="help-search" type="search" wire:model.live.debounce.300ms="q" maxlength="100" class="ui-control" placeholder="{{ __('Search guides') }}">
        @error('q') <p class="text-sm text-red">{{ $message }}</p> @enderror
        <label class="inline-flex min-h-10 items-center gap-2 text-sm"><input type="checkbox" wire:model.live="all">{{ __('All guides') }}</label>
    </x-ui.card>
    @foreach($sections as $section)
        @php($items = array_values(array_filter($articles, fn ($article) => $article->section === $section['slug'])))
        @if($items)
            <section class="space-y-3">
                <h2 class="text-lg font-bold text-ink">{{ $section['name'] }}</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($items as $article)
                        <a href="{{ $article->url() }}" class="ui-card block p-5 transition-colors hover:border-primary focus-visible:outline-2 focus-visible:outline-primary">
                            <strong class="block text-primary">{{ $article->title }}</strong>
                            <p class="mt-2 text-sm leading-6 text-gray-600">{{ $article->summary }}</p>
                        </a>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach
    @if(!$articles)<x-ui.empty :title="__('No guides found.')" />@endif
</div>
