<div class="space-y-5">
    <x-ui.page-header :title="__('Edit defense request letter')" back="/cases/{{ $record->id }}" />
    @if($notice)<x-ui.alert tone="amber" role="status">{{ $notice }}</x-ui.alert>@endif
    @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
    @include('livewire.cases.partials.ai-editor-controls', ['case' => $record])
    @include('livewire.cases.partials.citation-review')
    <div class="grid gap-5 lg:grid-cols-2">
        <x-ui.card class="space-y-5">
            @foreach($languages as $language)
                <div wire:key="language-{{ $language }}">
                    <label for="body-{{ $language }}" class="mb-2 block font-bold">{{ strtoupper($language) }} — {{ __('Letter text') }}</label>
                    <x-ui.textarea id="body-{{ $language }}" wire:model="bodies.{{ $language }}" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" rows="11" class="font-amiri text-lg" />
                    @error('bodies.'.$language)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
            @endforeach
            <p class="text-xs text-gray-500">{{ __('You can use Markdown headings, lists, and emphasis.') }}</p>
            <div class="flex flex-wrap gap-3">
                <x-ui.button wire:click="save">{{ __('Save draft') }}</x-ui.button>
                <x-ui.button variant="secondary" wire:click="submit">{{ __('Submit for review') }}</x-ui.button>
                @if($record->status === \App\Enums\CaseStatus::DefenseLetterCitationCheck || $record->status === \App\Enums\CaseStatus::DefenseLetterReadyForReview)<x-ui.button variant="ghost" wire:click="back">{{ __('Back to editing') }}</x-ui.button>@endif
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Document preview')" class="bg-gray-50">
            @if($documentId)
                <x-ui.file-action :item="\App\Support\Files\FileItem::forDocument($document)" :show-download="false" />
                <iframe title="{{ __('Document preview') }}" sandbox="" src="{{ route('documents.preview', $documentId) }}" class="h-[700px] w-full rounded-2xl border border-gray-200 bg-white"></iframe>
                <p class="mt-2 text-xs text-gray-500">{{ __('Save the draft to update the preview.') }}</p>
            @else
                <p class="text-gray-500">{{ __('Save the draft to view the preview.') }}</p>
            @endif
        </x-ui.card>
    </div>
</div>
