@if($document && $document->citations()->exists())
<x-ui.card class="space-y-4" aria-label="{{ __('Citation check') }}">
    <div class="flex items-center justify-between gap-3"><h2 class="font-bold">{{ __('Citation check') }}</h2>
        @unless($document->is_locked)<x-ui.button type="button" variant="ghost" wire:click="recheckCitations">{{ __('Recheck') }}</x-ui.button>@endunless
    </div>
    @foreach($document->citations()->with('legalUnit.law')->orderBy('id')->get() as $citation)
        @php
            $result = $citation->relevance_check?->value;
            $status = $citation->overridden ? __('Check overridden') : match($result) {
                'supports' => __('Supports'), 'partial' => __('Partly supports'), 'does_not_support' => __('Does not support'), default => $citation->created_at?->gt(now()->subSeconds(10)) ? __('Checking') : __('Not checked'),
            };
            $tone = $citation->overridden ? 'amber' : match ($result) { 'supports' => 'green', 'does_not_support' => 'red', default => 'amber' };
        @endphp
        <div wire:key="citation-{{ $citation->id }}" class="border-t pt-4 space-y-2">
            <p class="text-sm">{{ preg_replace_callback('/\[\[cite:(\d+)\]\]/', fn ($m) => __('Article').' ('.(\App\Models\LegalUnit::withoutGlobalScopes()->find((int) $m[1])?->article_number ?? $m[1]).')', $citation->claim_sentence) }} <x-ui.badge :tone="$tone">{{ $status }}</x-ui.badge></p>
            <p class="text-xs text-gray-500">{{ $citation->legalUnit?->law?->name }} — {{ __('Article') }} {{ $citation->legalUnit?->article_number }} {{ $citation->legalUnit?->clause_path }}</p>
            @if($citation->relevance_reason)<p class="text-sm">{{ $citation->relevance_reason }}</p>@endif
            @if($citation->overridden)<p class="text-sm text-amber">{{ __('Override reason') }}: {{ $citation->override_reason }}</p>@endif
            @unless($document->is_locked)
                @if($citation->legalUnit?->superseded_by_id)
                    <x-ui.button type="button" variant="secondary" wire:click="replaceCitation({{ $citation->legal_unit_id }})">{{ __('Replace with corrected text') }}</x-ui.button>
                @endif
                @unless($citation->overridden || $result === 'supports')
                    <label class="block text-sm">{{ __('Reason for overriding the check') }}
                        <x-ui.input wire:model="overrideReasons.{{ $citation->id }}" class="mt-1" maxlength="1000" />
                    </label>
                    @error('overrideReasons.'.$citation->id)<p role="alert" class="text-red text-sm">{{ $message }}</p>@enderror
                    <x-ui.button type="button" variant="secondary" wire:click="overrideCitation({{ $citation->id }})">{{ __('Record override') }}</x-ui.button>
                @endunless
            @endunless
        </div>
    @endforeach
</x-ui.card>
@endif
