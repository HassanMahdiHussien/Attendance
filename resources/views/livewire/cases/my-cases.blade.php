<div>
    <x-ui.page-header :title="__('My cases')" />
    @if($summary)
        <div class="mb-6 grid gap-3 sm:grid-cols-3">
            <x-ui.stat :label="__('Consultations awaiting review')" :value="\App\Support\Format\Ui::number($summary['waiting'])" tone="amber" />
            <x-ui.stat :label="__('Consultations completed this month')" :value="\App\Support\Format\Ui::number($summary['completed_month'])" tone="green" />
            <x-ui.card :title="__('Oldest pending consultation')">
                @if($summary['oldest'])
                    <p class="text-sm">{{ __('Procedure due') }}: {{ $summary['oldest']['due'] ? \App\Support\Format\Ui::date($summary['oldest']['due']) : __('Not set') }}</p>
                    @if($summary['oldest']['url'])<a class="ui-link text-sm font-bold" href="{{ $summary['oldest']['url'] }}">{{ __('View case') }}</a>@endif
                @else
                    <p class="text-sm text-gray-500">{{ __('No pending consultation.') }}</p>
                @endif
            </x-ui.card>
        </div>
    @endif
    <x-ui.card padding="none">
        @forelse($cases as $case)
            <a href="/cases/{{ $case->id }}" class="flex min-h-16 items-center gap-3 border-b border-[#EEF0F4] px-5 py-4 text-sm hover:bg-[#FAFAFC] focus-visible:outline-2 focus-visible:outline-primary">
                <span class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary-light font-bold text-primary">{{ \App\Support\Format\Initials::for($case->employee?->name ?? '') }}</span>
                <span class="min-w-0 flex-1 font-bold">{{ $case->employee?->name }}</span>
                <bdi class="font-mono text-xs text-gray-500">#{{ \App\Support\Format\Ui::number($case->case_number) }}</bdi>
                <x-ui.icon name="chevron" class="h-4 w-4 text-gray-400 rtl:rotate-180" />
            </a>
        @empty
            <x-ui.empty :title="__('No cases are currently available to you.')" />
        @endforelse
    </x-ui.card>
</div>
