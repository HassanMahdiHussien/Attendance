@props(['variant' => 'primary', 'size' => 'normal', 'href' => null])
@php($classes = 'ui-button ui-button-'.$variant.($size === 'small' ? ' ui-button-small' : ''))
@if($href)
    <a href="{{ $href }}" {{ $attributes->class([$classes]) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->class([$classes])->merge(['type' => 'button']) }}>{{ $slot }}</button>
@endif
