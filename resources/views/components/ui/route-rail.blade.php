@props(['steps'])
@php
    $labels = [
        'upload' => __('messages.clear_screens.journey_upload'),
        'check' => __('messages.clear_screens.journey_check'),
        'results' => __('messages.clear_screens.journey_results'),
        'review' => __('messages.clear_screens.journey_review'),
    ];
@endphp
<ol {{ $attributes->class(['flex flex-wrap gap-4 rounded-xl bg-night-2 p-5 text-white']) }}>
    @foreach ($steps as $step)
        <li class="flex min-w-28 flex-1 items-center gap-2 text-sm">
            <span class="h-4 w-4 shrink-0 rounded-full border-2 {{ $step['state'] === 'current' ? 'border-signal bg-signal ring-4 ring-signal/25' : ($step['state'] === 'done' ? 'border-green bg-green' : 'border-line bg-white') }}"></span>
            <span>{{ $labels[$step['key']] }}</span>
        </li>
    @endforeach
</ol>
