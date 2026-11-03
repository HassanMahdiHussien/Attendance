<x-filament-widgets::widget>
    <x-filament::section :heading="__('messages.setup.title')">
        <div class="space-y-3">
            @foreach($this->items() as $item)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 py-2">
                    <div>
                        <strong>{{ $item['label'] }}</strong>
                        <p class="text-sm text-gray-500"><x-ui.badge :tone="$item['done'] ? 'green' : 'red'">{{ $item['done'] ? __('messages.setup.done') : __('messages.clear_screens.needs_action') }}</x-ui.badge> {{ $item['done'] ? '' : $item['reason'] }}</p>
                    </div>
                    @unless($item['done'])
                        <a class="text-primary-600 underline" href="{{ $item['url'] }}">{{ __('messages.setup.fix') }}</a>
                    @endunless
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
