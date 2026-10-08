@props(['label', 'error' => null, 'hint' => null, 'for' => null])
<div {{ $attributes->class(['min-w-0']) }}>
    <label class="mb-1 block text-sm font-bold" @if($for) for="{{ $for }}" @endif>{{ $label }}</label>
    {{ $slot }}
    @if($hint)<p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>@endif
    @if($error)@error($error)<p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p>@enderror@endif
</div>
