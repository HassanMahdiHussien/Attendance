<x-filament-panels::page>
    @foreach ($this->needsReviewLanguages() as $language)
        <p role="alert" style="color:#b45309">{{ __('The :language letterhead needs review before documents are issued.', ['language' => $language]) }}</p>
    @endforeach
    <form wire:submit="save" class="space-y-6 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
        {{ $this->form }}
        @if(($data['internal_regulation_answer'] ?? null) === 'yes')
            <a href="{{ \App\Filament\Admin\Pages\InternalRegulation::getUrl(panel: 'admin', tenant: \Filament\Facades\Filament::getTenant()) }}" class="text-primary-600 underline">{{ __('messages.setup.upload_regulation') }}</a>
        @endif
        <x-filament::button type="submit">{{ __('Save settings') }}</x-filament::button>
    </form>
</x-filament-panels::page>
