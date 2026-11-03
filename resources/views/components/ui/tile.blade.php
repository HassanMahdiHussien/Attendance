@props(['label', 'value', 'tone' => 'white', 'href' => null, 'action' => null])
@php
    $classes = 'block w-full rounded-xl border p-4 text-start focus-visible:outline-2 focus-visible:outline-primary '.match ($tone) {
        'red' => 'border-red/25 bg-red-light',
        'amber' => 'border-amber/25 bg-amber-light',
        default => 'border-line bg-white',
    };
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class([$classes]) }}><span class="block text-sm text-gray-600">{{ $label }}</span><strong class="mt-1 block text-xl text-ink"><bdi dir="auto">{{ $value }}</bdi></strong>{{ $slot }}</a>
@else
    <button type="button" @if ($action) wire:click="{{ $action }}" @endif {{ $attributes->class([$classes]) }}><span class="block text-sm text-gray-600">{{ $label }}</span><strong class="mt-1 block text-xl text-ink"><bdi dir="auto">{{ $value }}</bdi></strong>{{ $slot }}</button>
@endif
