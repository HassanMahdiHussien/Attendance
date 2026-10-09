@if($comparison)
    <section class="rounded-2xl border border-indigo-200 bg-white p-4 space-y-4" aria-label="{{ __('messages.regulation.title') }}">
        <h2 class="text-lg font-bold">{{ __('messages.regulation.title') }}</h2>
        @if($comparison->status === 'suggesting')
            <p wire:poll.3s="pollAiDraft" role="status">{{ __('messages.regulation.suggesting') }}</p>
        @elseif(in_array($comparison->status, ['ready', 'failed']))
            @if($comparison->status === 'failed')<x-ui.alert tone="amber">{{ __('messages.regulation.ai_unavailable') }}</x-ui.alert>@endif
            <p class="text-sm text-gray-600">{{ __('messages.regulation.retrieved_only') }}</p>
            @foreach($comparison->pairs()->with(['lawUnit', 'regulationUnit'])->get() as $pair)
                <div class="rounded-xl border p-3 space-y-3" wire:key="comparison-pair-{{ $pair->id }}">
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><strong>{{ __('messages.regulation.law') }} #{{ $pair->law_unit_id }}</strong><p class="whitespace-pre-wrap">{{ $pair->lawUnit?->official_text }}</p>@if($pair->lawUnit?->import_run_id && $pair->lawUnit?->source_page)<a class="ui-link" href="{{ route('legal-library.pages.show', ['run' => $pair->lawUnit->import_run_id, 'page' => $pair->lawUnit->source_page]) }}">{{ __('messages.regulation.source_page') }}</a>@endif</div>
                        <div><strong>{{ __('messages.regulation.internal') }} #{{ $pair->regulation_unit_id }}</strong><p class="whitespace-pre-wrap">{{ $pair->regulationUnit?->official_text }}</p>@if($pair->regulationUnit?->import_run_id && $pair->regulationUnit?->source_page)<a class="ui-link" href="{{ route('legal-library.pages.show', ['run' => $pair->regulationUnit->import_run_id, 'page' => $pair->regulationUnit->source_page]) }}">{{ __('messages.regulation.source_page') }}</a>@endif</div>
                    </div>
                    @if($pair->ai_verdict)<p class="text-sm">{{ __('messages.regulation.suggestion') }}: {{ __('messages.regulation.'.$pair->ai_verdict) }} — {{ $pair->ai_reason }}</p>@endif
                    <x-ui.select wire:model="comparisonVerdicts.{{ $pair->id }}" aria-label="{{ __('messages.regulation.verdict') }}">
                        @foreach(['consistent', 'conflict', 'more_favourable', 'not_relevant'] as $verdict)
                            <option value="{{ $verdict }}" @selected($pair->verdict === $verdict)>{{ __('messages.regulation.'.$verdict) }}</option>
                        @endforeach
                    </x-ui.select>
                </div>
            @endforeach
            @php
                $citable = \App\Models\LegalUnit::query()->citableFor($comparison->disciplinaryCase)->with('law')->get();
                $lawOptions = $citable->filter(fn ($unit) => $unit->law->kind->value === 'statute');
                $regulationOptions = $citable->filter(fn ($unit) => $unit->law->kind->value === 'internal_regulation');
            @endphp
            <div class="grid gap-2 md:grid-cols-3">
                <x-ui.select wire:model="comparisonLawId" aria-label="{{ __('messages.regulation.law') }}"><option value="">{{ __('messages.regulation.law') }}</option>@foreach($lawOptions as $unit)<option value="{{ $unit->id }}">{{ $unit->law->name }} — {{ $unit->article_number }}</option>@endforeach</x-ui.select>
                <x-ui.select wire:model="comparisonRegulationId" aria-label="{{ __('messages.regulation.internal') }}"><option value="">{{ __('messages.regulation.internal') }}</option>@foreach($regulationOptions as $unit)<option value="{{ $unit->id }}">{{ $unit->law->name }} — {{ $unit->article_number }}</option>@endforeach</x-ui.select>
                <x-ui.select wire:model="comparisonManualVerdict" aria-label="{{ __('messages.regulation.verdict') }}">@foreach(['consistent', 'conflict', 'more_favourable', 'not_relevant'] as $verdict)<option value="{{ $verdict }}">{{ __('messages.regulation.'.$verdict) }}</option>@endforeach</x-ui.select>
            </div>
            <x-ui.button type="button" variant="secondary" wire:click="addComparisonPair">{{ __('messages.regulation.add_pair') }}</x-ui.button>
            @error('pair')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
            <div><x-ui.button type="button" wire:click="confirmComparison">{{ __('messages.regulation.confirm') }}</x-ui.button></div>
            <label class="block">{{ __('messages.regulation.skip_reason') }}<x-ui.textarea wire:model="comparisonSkipReason" rows="2" class="mt-1" /></label>
            @error('skipReason')<p role="alert" class="text-red-700">{{ $message }}</p>@enderror
            <x-ui.button type="button" variant="secondary" wire:click="skipComparison">{{ __('messages.regulation.skip') }}</x-ui.button>
        @else
            <p>{{ $comparison->status === 'skipped' ? __('messages.regulation.law_only') : __('messages.regulation.confirmed') }}</p>
        @endif
    </section>
@endif
