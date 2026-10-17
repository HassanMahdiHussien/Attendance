@props(['step', 'closed' => false])
{{-- The case route: five stations on one line; done stations are filled, the current one is the only signal-amber point. --}}
<ol {{ $attributes->class(['grid grid-cols-5 text-center text-[0.7rem] sm:text-sm']) }}>
    @for($i = 1; $i <= 5; $i++)
        <li aria-current="{{ ! $closed && $i === $step ? 'step' : 'false' }}" class="relative min-w-0">
            @if($i > 1)<span class="absolute top-[0.6875rem] h-[3px] w-full -translate-y-1/2 end-1/2 {{ $i <= $step ? 'bg-night' : 'bg-line' }}" aria-hidden="true"></span>@endif
            <span class="relative mx-auto mb-2 block h-[1.375rem] w-[1.375rem] rounded-full border-[3px] {{ $closed ? 'border-green bg-green' : ($i < $step ? 'border-night bg-night' : ($i === $step ? 'border-signal bg-signal ring-4 ring-signal-light' : 'border-line bg-white')) }}" aria-hidden="true"></span>
            <span class="block leading-tight {{ $closed ? 'text-green' : ($i === $step ? 'font-semibold text-ink' : ($i < $step ? 'text-gray-600' : 'text-gray-400')) }}">{{ $closed && $i === 5 ? __('Closed') : \App\Support\Format\StageIndicator::stageLabel($i) }}</span>
        </li>
    @endfor
</ol>
