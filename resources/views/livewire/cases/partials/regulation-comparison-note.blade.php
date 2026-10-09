@if($document->regulationComparison && in_array($document->regulationComparison->status, ['confirmed', 'skipped']))
    @php($note = $document->regulationComparison)
    <aside class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 space-y-3" aria-label="{{ __('messages.regulation.title') }}">
        <h2 class="font-bold">{{ __('messages.regulation.title') }}</h2>
        <p class="text-sm">{{ __('messages.regulation.confirmed_by') }}: {{ $note->confirmer?->name }} — {{ $note->confirmed_at?->format('Y-m-d H:i') }}</p>
        @if($note->status === 'skipped')
            <p>{{ __('messages.regulation.law_only') }}</p><p class="whitespace-pre-wrap">{{ $note->skip_reason }}</p>
        @else
            @foreach($note->pairs()->with(['lawUnit', 'regulationUnit'])->get() as $pair)
                <div class="rounded-xl bg-white p-3 space-y-2">
                    <p><strong>{{ __('messages.regulation.verdict') }}:</strong> {{ __('messages.regulation.'.$pair->verdict) }}
                        @if($pair->ai_verdict) · {{ __('messages.regulation.suggestion') }}: {{ __('messages.regulation.'.$pair->ai_verdict) }} — {{ $pair->ai_reason }} @endif</p>
                    <div class="grid gap-3 md:grid-cols-2">
                        <div><strong>{{ __('messages.regulation.law') }} #{{ $pair->law_unit_id }}</strong><p class="whitespace-pre-wrap">{{ $pair->lawUnit?->official_text }}</p>@if($pair->lawUnit?->import_run_id && $pair->lawUnit?->source_page)<a class="ui-link" href="{{ route('legal-library.pages.show', ['run' => $pair->lawUnit->import_run_id, 'page' => $pair->lawUnit->source_page]) }}">{{ __('messages.regulation.source_page') }}</a>@endif</div>
                        <div><strong>{{ __('messages.regulation.internal') }} #{{ $pair->regulation_unit_id }}</strong><p class="whitespace-pre-wrap">{{ $pair->regulationUnit?->official_text }}</p>@if($pair->regulationUnit?->import_run_id && $pair->regulationUnit?->source_page)<a class="ui-link" href="{{ route('legal-library.pages.show', ['run' => $pair->regulationUnit->import_run_id, 'page' => $pair->regulationUnit->source_page]) }}">{{ __('messages.regulation.source_page') }}</a>@endif</div>
                    </div>
                </div>
            @endforeach
        @endif
    </aside>
@endif
@if($document->supplementaryComparison && in_array($document->supplementaryComparison->status, ['confirmed', 'skipped']))
    @php($supplement = $document->supplementaryComparison)
    <aside class="rounded-2xl border border-indigo-200 bg-indigo-50 p-4 space-y-3">
        <h2 class="font-bold">{{ __('messages.regulation.supplement') }}</h2>
        <p class="text-sm">{{ __('messages.regulation.confirmed_by') }}: {{ $supplement->confirmer?->name }} — {{ $supplement->confirmed_at?->format('Y-m-d H:i') }}</p>
        @if($supplement->status === 'skipped')<p>{{ __('messages.regulation.law_only') }} {{ $supplement->skip_reason }}</p>@endif
        @foreach($supplement->pairs()->with(['lawUnit', 'regulationUnit'])->get() as $pair)
            <div class="grid gap-3 rounded-xl bg-white p-3 md:grid-cols-2">
                <p>{{ __('messages.regulation.'.$pair->verdict) }} — {{ $pair->lawUnit?->official_text }}</p>
                <p>{{ $pair->regulationUnit?->official_text }}</p>
            </div>
        @endforeach
    </aside>
@endif
