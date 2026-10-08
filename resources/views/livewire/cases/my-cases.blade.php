<div>
    <h1 class="text-2xl font-black mb-6">{{ __('My cases') }}</h1>
    @if($summary)
        <div class="grid sm:grid-cols-3 gap-3 mb-6">
            <div class="bg-white rounded-2xl p-5 shadow-sm">{{ __('Consultations awaiting review') }}<strong class="block text-2xl text-primary">{{ \App\Support\Format\Ui::number($summary['waiting']) }}</strong></div>
            <div class="bg-white rounded-2xl p-5 shadow-sm">{{ __('Consultations completed this month') }}<strong class="block text-2xl text-primary">{{ \App\Support\Format\Ui::number($summary['completed_month']) }}</strong></div>
            <div class="bg-white rounded-2xl p-5 shadow-sm">{{ __('Oldest pending consultation') }}
                @if($summary['oldest'])
                    <span class="block text-sm">{{ __('Procedure due') }}: {{ $summary['oldest']['due'] ? \App\Support\Format\Ui::date($summary['oldest']['due']) : __('Not set') }}</span>
                    @if($summary['oldest']['url'])<a class="text-primary text-sm font-bold" href="{{ $summary['oldest']['url'] }}">{{ __('View case') }}</a>@endif
                @else<span class="block text-sm text-gray-400">{{ __('No pending consultation.') }}</span>@endif
            </div>
        </div>
    @endif
    <div class="space-y-3">@forelse($cases as $case)<a href="/cases/{{ $case->id }}" class="block bg-white rounded-2xl p-5 shadow-sm"><span class="font-bold">{{ $case->employee?->name }}</span><span class="text-xs font-mono text-gray-400 ms-2">{{ \App\Support\Format\Ui::number($case->case_number) }}</span></a>@empty<p class="bg-white rounded-2xl p-8 text-center text-gray-500">{{ __('No cases are currently available to you.') }}</p>@endforelse</div>
</div>
