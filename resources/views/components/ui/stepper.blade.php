@props(['step'])
<ol {{ $attributes->class(['grid grid-cols-5 gap-1 text-center text-[0.7rem] sm:text-sm']) }}>
    @for($i = 1; $i <= 5; $i++)
        <li aria-current="{{ $i === $step ? 'step' : 'false' }}" class="min-w-0">
            <span class="mx-auto mb-2 grid h-8 w-8 place-items-center rounded-full {{ $i < $step ? 'bg-primary text-white' : ($i === $step ? 'border-2 border-primary text-primary font-bold' : 'bg-gray-100 text-gray-500') }}">@if($i < $step)<x-ui.icon name="check" class="h-4 w-4" />@else{{ \App\Support\Format\Ui::number($i) }}@endif</span>
            <span class="block leading-tight {{ $i === $step ? 'font-bold text-primary' : 'text-gray-500' }}">{{ \App\Support\Format\StageIndicator::stageLabel($i) }}</span>
        </li>
    @endfor
</ol>
