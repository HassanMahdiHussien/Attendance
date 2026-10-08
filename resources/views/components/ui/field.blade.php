@props(['label', 'error' => null, 'hint' => null, 'for' => null])
@php($errorBag = $errors ?? new \Illuminate\Support\ViewErrorBag)
<div {{ $attributes->class(['min-w-0']) }}>
    <label class="mb-1 block text-sm font-bold" @if($for) for="{{ $for }}" @endif>{{ $label }}</label>
    {{ $slot }}
    @if($hint)<p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>@endif
    @if($error && $errorBag->has($error))<p class="mt-1 text-sm text-red-700" role="alert">{{ $errorBag->first($error) }}</p>@endif
</div>
