<x-filament-panels::page>
    @foreach ($this->needsReviewLanguages() as $language)
        <p role="alert" style="color:#b45309">{{ __('The :language letterhead needs review before documents are issued.', ['language' => $language]) }}</p>
    @endforeach
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}
        <x-filament::button type="submit">{{ __('Save settings') }}</x-filament::button>
    </form>
</x-filament-panels::page>
