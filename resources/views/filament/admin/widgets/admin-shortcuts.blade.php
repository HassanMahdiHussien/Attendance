<x-filament-widgets::widget>
    <x-filament::section :heading="__('Quick links')">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($this->links() as $link)
                <a href="{{ $link['url'] }}" class="rounded-lg border border-gray-200 px-4 py-3 text-sm font-medium text-primary-700 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">{{ $link['label'] }}</a>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
