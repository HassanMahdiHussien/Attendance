@props(['title', 'subtitle' => null, 'back' => null])
<header {{ $attributes->class(['mb-6 flex flex-wrap items-end justify-between gap-3']) }}>
    <div class="min-w-0">
        @if($back)<a href="{{ $back }}" class="ui-link mb-2 inline-flex items-center gap-1 text-sm"><x-ui.icon name="arrow" class="h-4 w-4 rtl:rotate-180" />{{ __('Back') }}</a>@endif
        <h1 class="text-2xl font-extrabold leading-tight">{{ $title }}</h1>
        @if($subtitle)<p class="mt-1 text-sm text-gray-500">{{ $subtitle }}</p>@endif
    </div>
    @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
</header>
