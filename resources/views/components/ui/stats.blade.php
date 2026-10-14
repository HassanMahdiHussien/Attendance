@props(['title' => null])
{{-- One panel of figures separated by hairlines; the negative margins hide the trailing borders of the last row and column. --}}
<section class="ui-card overflow-hidden">
    @if($title)<h2 class="border-b border-line px-4 py-3 text-sm font-bold text-ink sm:px-5">{{ $title }}</h2>@endif
    <div {{ $attributes->class(['-mb-px -me-px grid grid-cols-2']) }}>
        {{ $slot }}
    </div>
</section>
