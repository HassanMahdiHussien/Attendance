@props(['size' => 'md', 'name' => true, 'subtitle' => null])
@php
    $tile = match ($size) {
        'sm' => 'h-7 w-7 text-sm',
        'lg' => 'h-12 w-12 text-2xl',
        default => 'h-8 w-8 text-base',
    };
@endphp
<span {{ $attributes->class(['inline-flex min-w-0 items-center gap-2.5']) }}>
    <span data-brand-mark class="grid shrink-0 place-items-center rounded-md font-mono font-bold text-night {{ $tile }}" style="background: linear-gradient(to bottom, var(--color-signal, #F2B33D) 0 48%, var(--color-signal-dark, #DE9A1A) 48% 52%, var(--color-signal, #F2B33D) 52% 100%)" aria-hidden="true">A</span>
    @if($name)
        <span class="min-w-0 leading-tight"><strong class="block text-[0.95rem] font-semibold">{{ __('Attendance') }}</strong>@if($subtitle)<span class="hidden max-w-44 truncate text-xs text-night-soft sm:block">{{ $subtitle }}</span>@endif</span>
    @endif
</span>
