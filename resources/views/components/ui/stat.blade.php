@props(['label', 'value', 'hint' => null, 'tone' => 'gray', 'href' => null])
@php($tag = $href ? 'a' : 'div')
<{{ $tag }} @if($href) href="{{ $href }}" @endif {{ $attributes->class(['flex min-w-0 flex-col justify-between border-b border-e border-line px-4 py-3 sm:px-5 sm:py-4', 'hover:bg-gray-50 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary' => $href]) }}>
    <div class="text-sm {{ $tone === 'red' ? 'text-red-700' : 'text-gray-500' }}">{{ $label }}</div>
    <div class="mt-1 text-2xl font-bold {{ $tone === 'red' ? 'text-red-700' : ($tone === 'green' ? 'text-green-700' : 'text-ink') }}"><bdi class="font-mono">{{ $value }}</bdi></div>
    @if($hint)<div class="mt-1 text-xs text-gray-500">{{ $hint }}</div>@endif
</{{ $tag }}>
