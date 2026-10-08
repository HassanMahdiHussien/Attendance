@props(['label', 'value', 'hint' => null, 'tone' => 'gray'])
<x-ui.card {{ $attributes->class(['border-red-200' => $tone === 'red']) }}>
    <div class="text-sm {{ $tone === 'red' ? 'text-red-700' : 'text-gray-500' }}">{{ $label }}</div>
    <div class="mt-1 text-3xl font-extrabold {{ $tone === 'red' ? 'text-red-700' : ($tone === 'green' ? 'text-green-700' : '') }}"><bdi class="font-mono">{{ $value }}</bdi></div>
    @if($hint)<div class="mt-1 text-xs text-gray-500">{{ $hint }}</div>@endif
</x-ui.card>
