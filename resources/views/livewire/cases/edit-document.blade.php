<div class="space-y-6">
    <div><a href="/cases/{{ $record->id }}" class="text-primary text-sm">{{ app()->getLocale() === 'ar' ? '→' : '←' }} {{ __('Case') }}</a><h1 class="text-2xl font-black mt-3">{{ __('Edit defense request letter') }}</h1></div>
    @if($notice)<p role="status" class="rounded-2xl bg-amber-light text-amber p-4">{{ $notice }}</p>@endif
    @error('notice')<p role="alert" class="rounded-2xl bg-red-light text-red p-4">{{ $message }}</p>@enderror
    @include('livewire.cases.partials.ai-editor-controls', ['case' => $record])
    @include('livewire.cases.partials.citation-review')
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <section class="bg-white rounded-3xl p-6 space-y-5">
            @foreach($languages as $language)
                <div wire:key="language-{{ $language }}">
                    <label for="body-{{ $language }}" class="block font-bold mb-2">{{ strtoupper($language) }} — {{ __('Letter text') }}</label>
                    <textarea id="body-{{ $language }}" wire:model="bodies.{{ $language }}" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" rows="11" class="w-full rounded-2xl border border-gray-200 p-4 font-serif"></textarea>
                    @error('bodies.'.$language)<p class="text-red text-sm">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <p class="text-xs text-gray-500">{{ __('You can use Markdown headings, lists, and emphasis.') }}</p>
            <div class="flex flex-wrap gap-3">
                <button wire:click="save" class="bg-primary text-white rounded-2xl px-5 py-3 font-bold">{{ __('Save draft') }}</button>
                <button wire:click="submit" class="border border-primary text-primary rounded-2xl px-5 py-3 font-bold">{{ __('Submit for review') }}</button>
                @if($record->status === \App\Enums\CaseStatus::DefenseLetterCitationCheck || $record->status === \App\Enums\CaseStatus::DefenseLetterReadyForReview)
                    <button wire:click="back" class="border border-gray-200 rounded-2xl px-5 py-3">{{ __('Back to editing') }}</button>
                @endif
            </div>
        </section>
        <section class="bg-gray-50 rounded-3xl p-5"><h2 class="font-bold mb-4">{{ __('Document preview') }}</h2>
            @if($documentId)
                <iframe title="{{ __('Document preview') }}" sandbox="" src="{{ route('documents.preview', $documentId) }}" class="w-full h-[700px] bg-white rounded-2xl border border-gray-200"></iframe>
                <p class="text-xs text-gray-500 mt-2">{{ __('Save the draft to update the preview.') }}</p>
            @else
                <p class="text-gray-500">{{ __('Save the draft to view the preview.') }}</p>
            @endif
        </section>
    </div>
</div>
