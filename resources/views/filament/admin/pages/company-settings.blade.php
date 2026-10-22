<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        {{ $this->form }}
        <x-filament::button type="submit">{{ __('Save settings') }}</x-filament::button>
    </form>
</x-filament-panels::page>
