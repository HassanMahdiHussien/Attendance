<div class="space-y-5">
    <x-ui.page-header :title="__('Edit defense request letter')" back="/cases/{{ $record->id }}" />
    @if($notice)<x-ui.alert tone="amber" role="status">{{ $notice }}</x-ui.alert>@endif
    @error('notice')<x-ui.alert tone="red">{{ $message }}</x-ui.alert>@enderror
    @include('livewire.cases.partials.ai-editor-controls', ['case' => $record])
    @include('livewire.cases.partials.citation-review')
    @if(!$documentId && !$manualEditing)
        @if(!$aiBusy && !$comparison)<x-ui.card class="space-y-3"><h2 class="font-semibold">{{ $aiFailed ? __('AI drafting failed — you can write manually or try again') : ($aiAvailable ? __('Draft with AI or write it myself') : __('Write the defense letter')) }}</h2><x-ui.button variant="secondary" wire:click="writeManually">{{ __('Write it myself') }}</x-ui.button></x-ui.card>@endif
    @else
    @if($document && $document->drafted_by === \App\Enums\DraftedBy::Ai)<x-ui.alert tone="indigo">{{ __('AI draft — review the letter before continuing') }}</x-ui.alert>@endif
    <div class="grid gap-5 lg:grid-cols-2">
        <x-ui.card class="space-y-5">
            @foreach($languages as $language)
                <div wire:key="language-{{ $language }}">
                    <label for="body-{{ $language }}" class="mb-2 block font-bold">{{ strtoupper($language) }} — {{ __('Letter text') }}</label>
                    <x-ui.textarea id="body-{{ $language }}" wire:model.live.debounce.1000ms="bodies.{{ $language }}" dir="{{ $language === 'en' ? 'ltr' : 'rtl' }}" rows="11" class="font-amiri text-lg" />
                    @error('bodies.'.$language)<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                </div>
            @endforeach
            @if($document && ($notes = $document->aiDraftNotes()->latest('id')->first()))
                <aside class="rounded-lg bg-gray-50 p-4"><h2 class="font-semibold">{{ __('Drafting notes') }}</h2><ul class="mt-2 list-disc ps-5 text-sm">@foreach($notes->notes ?? [] as $note)<li>{{ is_array($note) ? __($note['key'], $note['params'] ?? []) : $note }}</li>@endforeach</ul></aside>
            @endif
            <p class="text-xs text-gray-500">{{ __('You can use Markdown headings, lists, and emphasis.') }}</p>
            <div class="flex flex-wrap gap-3">
                <x-ui.button variant="secondary" wire:click="save">{{ __('Save draft') }}</x-ui.button>
                @if($hasUnsavedChanges)<span class="self-center text-xs text-gray-500" role="status">{{ __('Unsaved changes') }}</span>@endif
                <x-ui.button wire:click="submit">{{ __('Review and continue') }}</x-ui.button>
                @if($record->status === \App\Enums\CaseStatus::DefenseLetterCitationCheck || $record->status === \App\Enums\CaseStatus::DefenseLetterReadyForReview)<x-ui.button variant="ghost" wire:click="back">{{ __('Back to editing') }}</x-ui.button>@endif
            </div>
        </x-ui.card>
        <x-ui.card :title="__('Document preview')" class="bg-gray-50">
            @if($documentId)
                <x-ui.file-action :item="\App\Support\Files\FileItem::forDocument($document)" :show-download="false" />
                <iframe title="{{ __('Document preview') }}" sandbox="" src="{{ route('documents.preview', ['document' => $documentId, 'token' => $previewToken, 'v' => $previewVersion]) }}" class="h-[700px] w-full rounded-2xl border border-gray-200 bg-white"></iframe>
            @else
                <p class="text-gray-500">{{ __('Save the draft to view the preview.') }}</p>
            @endif
        </x-ui.card>
    </div>
    @endif
</div>
