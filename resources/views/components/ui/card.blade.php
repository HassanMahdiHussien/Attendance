@props(['title' => null, 'padding' => 'normal'])
<section {{ $attributes->class(['ui-card', 'p-4 sm:p-5' => $padding === 'normal', 'p-0' => $padding === 'none']) }}>
    @if($title || isset($actions))
        <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
            @if($title)<h2 class="text-base font-extrabold">{{ $title }}</h2>@endif
            @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</section>
