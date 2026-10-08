<div class="max-w-5xl mx-auto space-y-5">
    <a href="/cases/{{ $case->id }}" class="text-primary text-sm">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Case') }}</a>
    <section class="bg-white rounded-3xl p-6 md:p-8 shadow-sm space-y-5">
        <h1 class="text-2xl font-black">{{ __('Committee recommendations') }}</h1>
        <p class="text-gray-500">{{ __('Write findings and recommendations in every enabled language. Arabic is authoritative.') }}</p>
        @if($notice)<p role="status" class="bg-amber-light text-amber rounded-2xl p-4">{{ $notice }}</p>@endif
        @error('notice')<p role="alert" class="bg-red-light text-red rounded-2xl p-4">{{ $message }}</p>@enderror
        @include('livewire.cases.partials.ai-editor-controls')
        @include('livewire.cases.partials.citation-review')
        @foreach($languages as $language)
            <div class="border-t pt-5 space-y-3" wire:key="language-{{ $language }}">
                <h2 class="font-black">{{ ['ar'=>__('Arabic'),'en'=>__('English'),'ku'=>__('Kurdish')][$language] ?? $language }}</h2>
                <label class="block font-bold">{{ __('Findings') }} <textarea wire:model="findings.{{ $language }}" rows="7" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
                <label class="block font-bold">{{ __('Recommendations') }} <textarea wire:model="recommendations.{{ $language }}" rows="7" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" class="block w-full rounded-xl border p-3 mt-2"></textarea></label>
            </div>
        @endforeach
        <div class="flex flex-wrap gap-3"><button wire:click="save" class="border border-primary text-primary rounded-2xl px-6 py-3">{{ __('Save') }}</button><button wire:click="submit" class="bg-primary text-white rounded-2xl px-6 py-3 font-bold">{{ __('Submit for approvals') }}</button></div>
        @if($document)<a href="{{ route('documents.preview', $document) }}" target="_blank" rel="noopener" class="text-primary underline">{{ __('Document preview') }}</a>@endif
    </section>
</div>
