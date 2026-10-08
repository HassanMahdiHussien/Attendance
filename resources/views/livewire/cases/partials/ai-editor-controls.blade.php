<div class="space-y-4" x-data="{ active: null, insert(marker) { if (!this.active) return; let el = this.active; let start = el.selectionStart; let value = el.value.slice(0, start) + marker + el.value.slice(el.selectionEnd); $wire.set(el.getAttribute('wire:model'), value); this.$nextTick(() => { el.focus(); el.setSelectionRange(start + marker.length, start + marker.length); }); } }" @focusin.window="if ($event.target.tagName === 'TEXTAREA' && ['bodies', 'findings', 'recommendations', 'reasons'].some(key => ($event.target.getAttribute('wire:model') || '').startsWith(key + '.'))) active = $event.target" @citation-selected.window="insert($event.detail.marker)">
    @if($aiFailed)<x-ui.alert tone="amber">{{ __('AI drafting failed — you can write manually or try again') }}</x-ui.alert>@endif
    @if($aiBusy)
        <p role="status" wire:poll.3s="pollAiDraft" class="rounded-2xl bg-primary-light p-4 text-indigo-700">{{ __('Drafting…') }}</p>
    @elseif($aiAvailable)
        @if($confirmAiReplacement)
            <div role="alert" class="flex flex-wrap gap-2 rounded-2xl bg-amber-light p-4 text-amber-700">
                <span>{{ __('This will replace the current text') }}</span>
                <x-ui.button type="button" variant="secondary" wire:click="requestAiDraft">{{ __('Confirm replacement') }}</x-ui.button>
                <x-ui.button type="button" variant="ghost" wire:click="$set('confirmAiReplacement', false)">{{ __('Cancel') }}</x-ui.button>
            </div>
        @else
            <x-ui.button type="button" variant="secondary" wire:click="requestAiDraft">{{ __('Draft with AI') }}</x-ui.button>
        @endif
    @endif
    <x-ui.button type="button" variant="secondary" wire:click="openCitationPicker">{{ __('Insert article') }}</x-ui.button>
    @if($citationPickerOpen)
        <div role="dialog" aria-modal="true" aria-label="{{ __('Insert article') }}" class="rounded-2xl border bg-white p-5 space-y-3">
            <div class="flex justify-between"><h2 class="font-bold">{{ __('Insert article') }}</h2><x-ui.button type="button" variant="ghost" wire:click="closeCitationPicker" aria-label="{{ __('Close') }}"><x-ui.icon name="x" class="h-4 w-4" /></x-ui.button></div>
            <x-ui.input wire:model.live.debounce.300ms="citationSearch" type="search" placeholder="{{ __('Law, article, clause, or text keywords') }}" />
            <div class="max-h-72 overflow-y-auto space-y-2">
                @forelse($units as $unit)
                    <x-ui.button type="button" variant="ghost" wire:key="picker-unit-{{ $unit->id }}" wire:click="selectCitation({{ $unit->id }})" class="block w-full text-start rounded-xl bg-gray-50 p-3 hover:bg-primary-light">
                        <strong>{{ $unit->law->name }} — {{ __('Article') }} {{ $unit->article_number }} {{ $unit->clause_path }}</strong>
                        <span class="block text-sm text-gray-500">{{ \Illuminate\Support\Str::limit($unit->official_text, 120) }}</span>
                    </x-ui.button>
                @empty<p class="text-gray-500">{{ __('No citable articles found.') }}</p>@endforelse
            </div>
        </div>
    @endif
    @if($document && ($notes = $document->aiDraftNotes()->latest('id')->first()))
        <aside class="rounded-2xl bg-gray-50 p-4"><h2 class="font-bold">{{ __('Drafting notes') }}</h2><ul class="list-disc ps-5">@foreach($notes->notes ?? [] as $note)<li>{{ is_array($note) ? __($note['key'], $note['params'] ?? []) : $note }}</li>@endforeach</ul></aside>
    @endif
</div>
