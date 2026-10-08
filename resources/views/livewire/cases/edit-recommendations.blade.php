<div class="mx-auto max-w-5xl space-y-5">
    <x-ui.page-header :title="__('Committee recommendations')" :subtitle="__('Write findings and recommendations in every enabled language. Arabic is authoritative.')" back="/cases/{{ $case->id }}" />
    <x-ui.card class="space-y-5">
        @if($notice)<x-ui.alert tone="amber" role="status">{{ $notice }}</x-ui.alert>@endif
        @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
        @include('livewire.cases.partials.ai-editor-controls')
        @include('livewire.cases.partials.citation-review')
        @foreach($languages as $language)
            <div class="space-y-3 border-t border-[#EEF0F4] pt-5" wire:key="language-{{ $language }}">
                <h2 class="font-extrabold">{{ ['ar' => __('Arabic'), 'en' => __('English'), 'ku' => __('Kurdish')][$language] ?? $language }}</h2>
                <label class="block font-bold">{{ __('Findings') }}<x-ui.textarea wire:model="findings.{{ $language }}" rows="7" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" class="mt-2 font-amiri text-lg" /></label>
                <label class="block font-bold">{{ __('Recommendations') }}<x-ui.textarea wire:model="recommendations.{{ $language }}" rows="7" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" class="mt-2 font-amiri text-lg" /></label>
            </div>
        @endforeach
        <div class="flex flex-wrap gap-3"><x-ui.button variant="secondary" wire:click="save">{{ __('Save') }}</x-ui.button><x-ui.button wire:click="submit">{{ __('Submit for approvals') }}</x-ui.button></div>
        @if($document)<a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener" class="ui-link">{{ __('Document preview') }}</a>@endif
    </x-ui.card>
</div>
