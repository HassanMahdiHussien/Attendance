@props(['selected' => false])
<button type="button" {{ $attributes->class(['min-h-11 rounded-lg px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-primary', 'bg-primary text-white' => $selected, 'bg-primary-light text-primary' => ! $selected]) }}>{{ $slot }}</button>
